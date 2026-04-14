# View Source Feature Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add "view source" buttons to homepage and docs page sections that open a modal with tabbed, syntax-highlighted component source code — showcasing Preflow's component model through dogfooding.

**Architecture:** Three new components (Tooltip, Modal, ViewSource) compose together. ViewSource renders a Lucide icon button wrapped in Tooltip; clicking opens a Modal with tabs. Each tab lazy-loads a CodeExample component via HTMX GET to a controller endpoint. Source code is served from pre-baked `.txt` snippet files.

**Tech Stack:** Preflow (components, routing, twig, htmx), Tempest Highlighter (via existing CodeExample), Lucide icons (via CDN already loaded)

**Design Spec:** `docs/superpowers/specs/2026-04-13-view-source-feature-design.md`

---

## File Structure

```
preflow-website/
├── app/
│   ├── Components/
│   │   ├── Tooltip/
│   │   │   ├── Tooltip.php          # Generic tooltip component
│   │   │   └── Tooltip.twig         # CSS-only tooltip with position support
│   │   ├── Modal/
│   │   │   ├── Modal.php            # Generic modal with optional tabs
│   │   │   └── Modal.twig           # Modal shell + JS for open/close/tabs
│   │   ├── ViewSource/
│   │   │   ├── ViewSource.php       # Glue: icon button + Tooltip + Modal
│   │   │   └── ViewSource.twig      # Button positioning + Modal instance
│   │   └── CodeExample/
│   │       └── snippets/
│   │           └── source/           # 18 new .txt snippet files
│   ├── Controllers/
│   │   └── SourceController.php     # HTMX endpoint for lazy-loading snippets
│   └── pages/
│       ├── index.twig               # Modified: ViewSource added to sections
│       └── docs/
│           └── [...path].twig       # Modified: ViewSource added to doc components
```

---

### Task 1: Tooltip Component

**Files:**
- Create: `app/Components/Tooltip/Tooltip.php`
- Create: `app/Components/Tooltip/Tooltip.twig`

- [ ] **Step 1: Create Tooltip.php**

```php
<?php

declare(strict_types=1);

namespace App\Components\Tooltip;

use Preflow\Components\Component;

final class Tooltip extends Component
{
    public string $text = '';
    public string $position = 'left';
    public string $icon = '';
    public string $ariaLabel = '';

    public function resolveState(): void
    {
        $this->text = $this->props['text'] ?? '';
        $this->position = $this->props['position'] ?? 'left';
        $this->icon = $this->props['icon'] ?? '';
        $this->ariaLabel = $this->props['ariaLabel'] ?? $this->text;
    }
}
```

- [ ] **Step 2: Create Tooltip.twig**

```twig
<span class="tooltip tooltip--{{ position }}">
    {% if icon %}
        <button class="tooltip-trigger" aria-label="{{ ariaLabel }}" type="button">
            <i class="{{ icon }}"></i>
        </button>
    {% else %}
        <span class="tooltip-trigger">
            <i class="icon-info"></i>
        </span>
    {% endif %}
    <span class="tooltip-text">{{ text }}</span>
</span>

{% apply css %}
.tooltip {
    position: relative;
    display: inline-flex;
}

.tooltip-trigger {
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    color: inherit;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.tooltip-text {
    position: absolute;
    background: var(--bg-elevated);
    color: var(--text-primary);
    font-size: 0.75rem;
    padding: 0.375rem 0.625rem;
    border-radius: 0.375rem;
    white-space: nowrap;
    box-shadow: var(--shadow-md);
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.15s, transform 0.15s;
    z-index: 100;
}

.tooltip--left .tooltip-text {
    right: calc(100% + 0.5rem);
    top: 50%;
    transform: translate(4px, -50%);
}

.tooltip--left .tooltip-text::after {
    content: '';
    position: absolute;
    left: 100%;
    top: 50%;
    transform: translateY(-50%);
    border: 5px solid transparent;
    border-left-color: var(--bg-elevated);
}

.tooltip:hover .tooltip-text,
.tooltip:focus-within .tooltip-text {
    opacity: 1;
}

.tooltip--left:hover .tooltip-text,
.tooltip--left:focus-within .tooltip-text {
    transform: translate(0, -50%);
}
{% endapply %}
```

- [ ] **Step 3: Verify Tooltip renders**

Start the dev server and temporarily add to `index.twig` after the Hero component:

```twig
{{ component('Tooltip', { text: 'Test tooltip', icon: 'icon-code' }) }}
```

Run: `cd /Users/smyr/Sites/gbits/preflow-website && php preflow serve`

Open http://localhost:8080 and hover over the icon. Verify tooltip appears to the left with correct styling. Remove the test line after verifying.

- [ ] **Step 4: Commit**

```bash
cd /Users/smyr/Sites/gbits/preflow-website
git add app/Components/Tooltip/Tooltip.php app/Components/Tooltip/Tooltip.twig
git commit -m "feat: add generic Tooltip component"
```

---

### Task 2: Modal Component

**Files:**
- Create: `app/Components/Modal/Modal.php`
- Create: `app/Components/Modal/Modal.twig`

- [ ] **Step 1: Create Modal.php**

```php
<?php

declare(strict_types=1);

namespace App\Components\Modal;

use Preflow\Components\Component;

final class Modal extends Component
{
    public string $modalId = '';
    public string $title = '';
    /** @var array<int, array{label: string, id: string, url: string}> */
    public array $tabs = [];

    public function resolveState(): void
    {
        $this->modalId = $this->props['id'] ?? '';
        $this->title = $this->props['title'] ?? '';
        $this->tabs = $this->props['tabs'] ?? [];
    }
}
```

- [ ] **Step 2: Create Modal.twig**

```twig
<div class="modal-backdrop" id="modal-{{ modalId }}" style="display:none;" onclick="Modal.close('{{ modalId }}')">
    <div class="modal-container" onclick="event.stopPropagation()">
        <div class="modal-header">
            {% if title %}
                <h3 class="modal-title">{{ title }}</h3>
            {% endif %}
            <button class="modal-close" onclick="Modal.close('{{ modalId }}')" aria-label="Close">
                <i class="icon-x"></i>
            </button>
        </div>
        {% if tabs|length > 0 %}
            <div class="modal-tabs">
                {% for tab in tabs %}
                    <button
                        class="modal-tab{% if loop.first %} modal-tab--active{% endif %}"
                        data-modal="{{ modalId }}"
                        data-tab="{{ tab.id }}"
                        onclick="Modal.switchTab('{{ modalId }}', '{{ tab.id }}')"
                        type="button"
                    >{{ tab.label }}</button>
                {% endfor %}
            </div>
            <div class="modal-body">
                {% for tab in tabs %}
                    <div
                        class="modal-panel{% if loop.first %} modal-panel--active{% endif %}"
                        id="panel-{{ modalId }}-{{ tab.id }}"
                        data-src="{{ tab.url }}"
                    >
                        <div class="modal-loading">Loading...</div>
                    </div>
                {% endfor %}
            </div>
        {% else %}
            <div class="modal-body">
                <div class="modal-panel modal-panel--active" id="panel-{{ modalId }}-default"></div>
            </div>
        {% endif %}
    </div>
</div>

{% apply css %}
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s;
}

.modal-backdrop.modal-open {
    opacity: 1;
}

.modal-container {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 0.75rem;
    box-shadow: var(--shadow-lg);
    width: min(48rem, calc(100vw - 2rem));
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    transform: scale(0.95);
    transition: transform 0.2s;
}

.modal-open .modal-container {
    transform: scale(1);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border);
}

.modal-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
}

.modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 0.25rem;
    display: flex;
    transition: color 0.15s;
}

.modal-close:hover {
    color: var(--text-primary);
}

.modal-tabs {
    display: flex;
    border-bottom: 1px solid var(--border);
    padding: 0 1.25rem;
    overflow-x: auto;
}

.modal-tab {
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: var(--text-muted);
    font-size: 0.8125rem;
    font-weight: 500;
    padding: 0.625rem 1rem;
    cursor: pointer;
    white-space: nowrap;
    transition: color 0.15s, border-color 0.15s;
}

.modal-tab:hover {
    color: var(--text-secondary);
}

.modal-tab--active {
    color: var(--accent);
    border-bottom-color: var(--accent);
}

.modal-body {
    overflow-y: auto;
    flex: 1;
    min-height: 0;
}

.modal-panel {
    display: none;
    padding: 0;
}

.modal-panel--active {
    display: block;
}

.modal-loading {
    padding: 2rem;
    text-align: center;
    color: var(--text-muted);
    font-size: 0.875rem;
}

@media (max-width: 640px) {
    .modal-container {
        width: calc(100vw - 1rem);
        max-height: 90vh;
        border-radius: 0.5rem;
    }

    .modal-tab {
        padding: 0.5rem 0.75rem;
        font-size: 0.75rem;
    }
}
{% endapply %}

{% apply js %}
window.Modal = {
    _open: null,

    open: function(id) {
        var el = document.getElementById('modal-' + id);
        if (!el) return;
        el.style.display = 'flex';
        requestAnimationFrame(function() {
            el.classList.add('modal-open');
        });
        document.body.style.overflow = 'hidden';
        this._open = id;

        // Load first tab content
        var firstPanel = el.querySelector('.modal-panel--active');
        if (firstPanel && firstPanel.dataset.src && firstPanel.querySelector('.modal-loading')) {
            this._loadPanel(firstPanel);
        }
    },

    close: function(id) {
        var el = document.getElementById('modal-' + id);
        if (!el) return;
        el.classList.remove('modal-open');
        setTimeout(function() { el.style.display = 'none'; }, 200);
        document.body.style.overflow = '';
        this._open = null;
    },

    switchTab: function(modalId, tabId) {
        var modal = document.getElementById('modal-' + modalId);
        if (!modal) return;

        modal.querySelectorAll('.modal-tab').forEach(function(t) {
            t.classList.toggle('modal-tab--active', t.dataset.tab === tabId);
        });
        modal.querySelectorAll('.modal-panel').forEach(function(p) {
            p.classList.remove('modal-panel--active');
        });

        var panel = document.getElementById('panel-' + modalId + '-' + tabId);
        if (panel) {
            panel.classList.add('modal-panel--active');
            if (panel.dataset.src && panel.querySelector('.modal-loading')) {
                this._loadPanel(panel);
            }
        }
    },

    _loadPanel: function(panel) {
        var url = panel.dataset.src;
        fetch(url).then(function(r) { return r.text(); }).then(function(html) {
            panel.innerHTML = html;
        }).catch(function() {
            panel.innerHTML = '<p style="padding:2rem;color:var(--text-muted)">Failed to load source.</p>';
        });
    }
};

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && Modal._open) {
        Modal.close(Modal._open);
    }
});
{% endapply %}
```

- [ ] **Step 3: Verify Modal renders**

Temporarily add to `index.twig` after the Hero component:

```twig
<button onclick="Modal.open('test')">Open Test Modal</button>
{{ component('Modal', {
    id: 'test',
    title: 'Test Modal',
    tabs: [
        { label: 'Tab 1', id: 'tab1', url: '' },
        { label: 'Tab 2', id: 'tab2', url: '' }
    ]
}) }}
```

Open http://localhost:8080 and click the button. Verify:
- Modal opens with backdrop, centered container, title, X button
- Tab bar shows two tabs with first active
- Clicking tab 2 switches the active panel
- Clicking X, backdrop, or pressing Escape closes the modal
- Body scroll is locked while open

Remove the test markup after verifying.

- [ ] **Step 4: Commit**

```bash
cd /Users/smyr/Sites/gbits/preflow-website
git add app/Components/Modal/Modal.php app/Components/Modal/Modal.twig
git commit -m "feat: add generic Modal component with tabs and lazy-loading"
```

---

### Task 3: Source Controller (HTMX Endpoint)

**Files:**
- Create: `app/Controllers/SourceController.php`

- [ ] **Step 1: Create SourceController.php**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Preflow\Components\ComponentRenderer;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;

#[Route('/api')]
final class SourceController
{
    public function __construct(
        private readonly ComponentRenderer $renderer,
    ) {}

    #[Get('/source')]
    public function source(ServerRequestInterface $request): string
    {
        $params = $request->getQueryParams();
        $file = $params['file'] ?? '';
        $lang = $params['lang'] ?? 'php';

        $allowedLangs = ['php', 'twig', 'html', 'javascript', 'css'];

        if (
            $file === ''
            || str_contains($file, '..')
            || !str_starts_with($file, 'source/')
            || !in_array($lang, $allowedLangs, true)
        ) {
            return '<p style="padding:2rem;color:var(--text-muted)">Invalid request.</p>';
        }

        $snippetPath = dirname(__DIR__) . '/Components/CodeExample/snippets/' . $file;
        if (!file_exists($snippetPath)) {
            return '<p style="padding:2rem;color:var(--text-muted)">Source file not found.</p>';
        }

        $code = file_get_contents($snippetPath);
        $title = basename($file, '.txt');
        // Convert "hero-php" to "Hero.php" style for display
        $parts = explode('-', $title);
        $ext = array_pop($parts);
        $name = implode('', array_map('ucfirst', $parts));
        $displayTitle = $name . '.' . $ext;

        $highlighter = new \Tempest\Highlight\Highlighter();
        $highlighted = $highlighter->parse($code, $lang);

        // Inline styles so the fragment is self-contained (CodeExample's co-located CSS
        // may not be loaded on every page that opens a ViewSource modal)
        return '<style>.vs-code{border-radius:0.75rem;border:1px solid var(--border);overflow:hidden;box-shadow:var(--shadow-md)}.vs-header{display:flex;justify-content:space-between;align-items:center;padding:0.625rem 1rem;background:var(--bg-card);border-bottom:1px solid var(--border)}.vs-title{font-size:0.8125rem;font-weight:600;color:var(--text-secondary)}.vs-lang{font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted)}.vs-pre{padding:1.25rem;background:var(--bg-code);font-family:"SF Mono","Fira Code","Cascadia Code",monospace;font-size:0.8125rem;line-height:1.7;overflow-x:auto;color:var(--text-secondary);white-space:pre-wrap;word-wrap:break-word}.vs-pre code{font-family:inherit;display:block;white-space:pre-wrap;word-wrap:break-word}.hl-keyword{color:var(--accent)}.hl-type{color:rgb(86,182,194)}.hl-value{color:rgb(152,195,121)}.hl-variable{color:rgb(224,108,117)}.hl-property{color:rgb(209,154,102)}.hl-comment{color:var(--text-muted);font-style:italic}</style>'
            . '<div class="vs-code"><div class="vs-header"><span class="vs-title">' . htmlspecialchars($displayTitle) . '</span><span class="vs-lang">' . htmlspecialchars($lang) . '</span></div><pre class="vs-pre"><code>' . $highlighted . '</code></pre></div>';
    }
}
```

Note: We render the HTML directly (not via `{{ component() }}`) because this is a controller, not a Twig template. The response includes an inline `<style>` block with all required CSS (`.vs-*` classes for layout, `.hl-*` classes for syntax highlighting) so the fragment is fully self-contained — it works regardless of whether CodeExample was rendered elsewhere on the page.

- [ ] **Step 2: Verify endpoint works**

With the dev server running, open in browser:

```
http://localhost:8080/api/source?file=source/hero-php.txt&lang=php
```

This will 404 because the snippet file doesn't exist yet. Instead, test with an existing snippet path first by temporarily modifying the validation to also allow files not starting with `source/`:

Actually, just test the validation first. Open:
- `http://localhost:8080/api/source?file=../../../etc/passwd&lang=php` — should show "Invalid request."
- `http://localhost:8080/api/source?file=source/nonexistent.txt&lang=php` — should show "Source file not found."
- `http://localhost:8080/api/source?file=source/test.txt&lang=badlang` — should show "Invalid request."

- [ ] **Step 3: Commit**

```bash
cd /Users/smyr/Sites/gbits/preflow-website
git add app/Controllers/SourceController.php
git commit -m "feat: add source controller for HTMX snippet loading"
```

---

### Task 4: ViewSource Component

**Files:**
- Create: `app/Components/ViewSource/ViewSource.php`
- Create: `app/Components/ViewSource/ViewSource.twig`

- [ ] **Step 1: Create ViewSource.php**

```php
<?php

declare(strict_types=1);

namespace App\Components\ViewSource;

use Preflow\Components\Component;

final class ViewSource extends Component
{
    /** @var array<int, array{label: string, language: string, snippet: string}> */
    public array $files = [];
    public string $tooltip = '';
    public string $uniqueId = '';
    /** @var array<int, array{label: string, id: string, url: string}> */
    public array $tabs = [];

    public function resolveState(): void
    {
        $this->files = $this->props['files'] ?? [];
        $this->tooltip = $this->props['tooltip'] ?? 'See how this was built';
        $this->uniqueId = 'vs-' . substr(md5(serialize($this->files)), 0, 8);

        $this->tabs = [];
        foreach ($this->files as $i => $file) {
            $this->tabs[] = [
                'label' => $file['label'],
                'id' => 'tab-' . $i,
                'url' => '/api/source?file=' . urlencode($file['snippet']) . '&lang=' . urlencode($file['language']),
            ];
        }
    }
}
```

- [ ] **Step 2: Create ViewSource.twig**

```twig
<div class="view-source">
    {{ component('Tooltip', {
        text: tooltip,
        position: 'left',
        icon: 'icon-code',
        ariaLabel: tooltip
    }) | raw }}
    <div class="view-source-click" onclick="Modal.open('{{ uniqueId }}')"></div>
</div>

{{ component('Modal', {
    id: uniqueId,
    title: 'Source Code',
    tabs: tabs
}) }}

{% apply css %}
.view-source {
    position: absolute;
    bottom: 1rem;
    right: 1rem;
    z-index: 10;
}

.view-source .tooltip-trigger {
    width: 2rem;
    height: 2rem;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.875rem;
    opacity: 0.7;
    transition: color 0.15s, box-shadow 0.15s, opacity 0.15s;
    cursor: pointer;
}

.view-source .tooltip-trigger:hover {
    color: var(--accent);
    box-shadow: var(--shadow-sm);
    opacity: 1;
}

.view-source-click {
    position: absolute;
    inset: 0;
    cursor: pointer;
}
{% endapply %}
```

Note: The `.view-source-click` overlay captures clicks on the tooltip trigger button and opens the modal. This avoids needing to add an `onclick` to the Tooltip component (keeping it generic).

- [ ] **Step 3: Commit**

```bash
cd /Users/smyr/Sites/gbits/preflow-website
git add app/Components/ViewSource/ViewSource.php app/Components/ViewSource/ViewSource.twig
git commit -m "feat: add ViewSource component (Tooltip + Modal + HTMX lazy-load)"
```

---

### Task 5: Create Snippet Files

**Files:**
- Create: 18 files under `app/Components/CodeExample/snippets/source/`

- [ ] **Step 1: Create the source/ directory**

```bash
mkdir -p /Users/smyr/Sites/gbits/preflow-website/app/Components/CodeExample/snippets/source
```

- [ ] **Step 2: Copy homepage component sources as snippets**

Copy each component's `.php` and `.twig` files, renaming to `.txt`:

```bash
cd /Users/smyr/Sites/gbits/preflow-website
cp app/Components/Hero/Hero.php app/Components/CodeExample/snippets/source/hero-php.txt
cp app/Components/Hero/Hero.twig app/Components/CodeExample/snippets/source/hero-twig.txt
cp app/Components/FeatureGrid/FeatureGrid.php app/Components/CodeExample/snippets/source/feature-grid-php.txt
cp app/Components/FeatureGrid/FeatureGrid.twig app/Components/CodeExample/snippets/source/feature-grid-twig.txt
cp app/Components/FeatureCard/FeatureCard.php app/Components/CodeExample/snippets/source/feature-card-php.txt
cp app/Components/FeatureCard/FeatureCard.twig app/Components/CodeExample/snippets/source/feature-card-twig.txt
cp app/Components/QuickStart/QuickStart.php app/Components/CodeExample/snippets/source/quick-start-php.txt
cp app/Components/QuickStart/QuickStart.twig app/Components/CodeExample/snippets/source/quick-start-twig.txt
cp app/Components/ArchitectureDiagram/ArchitectureDiagram.php app/Components/CodeExample/snippets/source/architecture-diagram-php.txt
cp app/Components/ArchitectureDiagram/ArchitectureDiagram.twig app/Components/CodeExample/snippets/source/architecture-diagram-twig.txt
cp app/Components/PackageCard/PackageCard.php app/Components/CodeExample/snippets/source/package-card-php.txt
cp app/Components/PackageCard/PackageCard.twig app/Components/CodeExample/snippets/source/package-card-twig.txt
```

- [ ] **Step 3: Copy docs component sources as snippets**

```bash
cd /Users/smyr/Sites/gbits/preflow-website
cp app/Components/DocsSidebar/DocsSidebar.php app/Components/CodeExample/snippets/source/docs-sidebar-php.txt
cp app/Components/DocsSidebar/DocsSidebar.twig app/Components/CodeExample/snippets/source/docs-sidebar-twig.txt
cp app/Components/DocsSearch/DocsSearch.php app/Components/CodeExample/snippets/source/docs-search-php.txt
cp app/Components/DocsSearch/DocsSearch.twig app/Components/CodeExample/snippets/source/docs-search-twig.txt
cp app/Components/DocsPage/DocsPage.php app/Components/CodeExample/snippets/source/docs-page-php.txt
cp app/Components/DocsPage/DocsPage.twig app/Components/CodeExample/snippets/source/docs-page-twig.txt
```

- [ ] **Step 4: Verify endpoint serves a snippet**

With the dev server running, open:

```
http://localhost:8080/api/source?file=source/hero-php.txt&lang=php
```

Should return syntax-highlighted HTML of the Hero component PHP source.

- [ ] **Step 5: Commit**

```bash
cd /Users/smyr/Sites/gbits/preflow-website
git add app/Components/CodeExample/snippets/source/
git commit -m "feat: add component source snippets for view-source feature"
```

---

### Task 6: Integrate ViewSource on Homepage

**Files:**
- Modify: `app/pages/index.twig`

- [ ] **Step 1: Add ViewSource to Hero section**

The Hero component's root `<section class="hero">` already has `position: relative` (set in Hero.twig line 24). Wrap the Hero call with a container that has `position: relative` so ViewSource can position against it:

In `index.twig`, replace the Hero component call (lines 6-9):

```twig
{{ component('Hero', {
    headline: 'The PHP framework<br>that embraces the browser.',
    subline: 'Components. HTML over the wire.<br>No build step. No JS framework.'
}) | raw }}
```

With:

```twig
<div style="position:relative">
{{ component('Hero', {
    headline: 'The PHP framework<br>that embraces the browser.',
    subline: 'Components. HTML over the wire.<br>No build step. No JS framework.'
}) | raw }}
{{ component('ViewSource', {
    files: [
        { label: 'Hero.php', language: 'php', snippet: 'source/hero-php.txt' },
        { label: 'Hero.twig', language: 'twig', snippet: 'source/hero-twig.txt' }
    ]
}) }}
</div>
```

- [ ] **Step 2: Add ViewSource to FeatureGrid section**

Wrap the FeatureGrid call (around line 15) in a relative-positioned container:

Replace:
```twig
{{ component('FeatureGrid', {
    features: [
        ...
    ]
}) }}
```

With:
```twig
<div style="position:relative">
{{ component('FeatureGrid', {
    features: [
        ...
    ]
}) }}
{{ component('ViewSource', {
    files: [
        { label: 'FeatureGrid.php', language: 'php', snippet: 'source/feature-grid-php.txt' },
        { label: 'FeatureGrid.twig', language: 'twig', snippet: 'source/feature-grid-twig.txt' },
        { label: 'FeatureCard.php', language: 'php', snippet: 'source/feature-card-php.txt' },
        { label: 'FeatureCard.twig', language: 'twig', snippet: 'source/feature-card-twig.txt' }
    ]
}) }}
</div>
```

- [ ] **Step 3: Add ViewSource to QuickStart section**

Wrap the QuickStart call (around line 50) in a relative-positioned container:

Replace:
```twig
{{ component('QuickStart') }}
```

With:
```twig
<div style="position:relative">
{{ component('QuickStart') }}
{{ component('ViewSource', {
    files: [
        { label: 'QuickStart.php', language: 'php', snippet: 'source/quick-start-php.txt' },
        { label: 'QuickStart.twig', language: 'twig', snippet: 'source/quick-start-twig.txt' }
    ]
}) }}
</div>
```

- [ ] **Step 4: Add ViewSource to ArchitectureDiagram section**

The `.arch-wrapper` div wraps the diagram (around line 98). Replace:

```twig
<div class="arch-wrapper">
    {{ component('ArchitectureDiagram') }}
</div>
```

With:

```twig
<div class="arch-wrapper" style="position:relative">
    {{ component('ArchitectureDiagram') }}
    {{ component('ViewSource', {
        files: [
            { label: 'ArchitectureDiagram.php', language: 'php', snippet: 'source/architecture-diagram-php.txt' },
            { label: 'ArchitectureDiagram.twig', language: 'twig', snippet: 'source/architecture-diagram-twig.txt' }
        ]
    }) }}
</div>
```

- [ ] **Step 5: Add ViewSource to Package Wall section**

The `.package-wall` section (around line 101) is already a section element. Add `position:relative` and ViewSource at the end, before the closing `</section>`:

Replace:
```twig
<section class="package-wall">
```

With:
```twig
<section class="package-wall" style="position:relative">
```

And before the closing `</section>` (around line 159), add:

```twig
    {{ component('ViewSource', {
        files: [
            { label: 'PackageCard.php', language: 'php', snippet: 'source/package-card-php.txt' },
            { label: 'PackageCard.twig', language: 'twig', snippet: 'source/package-card-twig.txt' }
        ]
    }) }}
```

- [ ] **Step 6: Verify all homepage ViewSource buttons**

Open http://localhost:8080 and check:
- Five `</>` icon buttons visible at bottom-right of: Hero, FeatureGrid, QuickStart, ArchitectureDiagram, Package Wall
- Hovering shows "See how this was built" tooltip to the left
- Clicking opens a modal with tabs showing syntax-highlighted source
- Clicking between tabs loads different source files
- Code Showcase section intentionally has no ViewSource button

- [ ] **Step 7: Commit**

```bash
cd /Users/smyr/Sites/gbits/preflow-website
git add app/pages/index.twig
git commit -m "feat: add ViewSource buttons to homepage sections"
```

---

### Task 7: Integrate ViewSource on Docs Page

**Files:**
- Modify: `app/pages/docs/[...path].twig`

- [ ] **Step 1: Add ViewSource to docs components**

The docs page uses a flex layout with `.docs-sidebar-wrapper`, DocsPage, and TableOfContents. We need to make each container `position: relative` and add ViewSource.

Replace the content block body (lines 11-25):

```twig
<div class="docs-layout">
    <div class="docs-sidebar-wrapper">
        {{ component('DocsSearch', { basePath: basePath }) }}
        {{ component('DocsSidebar', { manifest: manifest, basePath: basePath, currentPath: route.path }) }}
    </div>

    {{ component('DocsPage', {
        docPath: route.path,
        basePath: basePath,
        manifest: manifest,
        cachePath: cachePath
    }) }}

    {{ component('TableOfContents', { headings: docsHeadings ?? [] }) }}
</div>
```

With:

```twig
<div class="docs-layout">
    <div class="docs-sidebar-wrapper" style="position:relative">
        {{ component('DocsSearch', { basePath: basePath }) }}
        {{ component('DocsSidebar', { manifest: manifest, basePath: basePath, currentPath: route.path }) }}
        {{ component('ViewSource', {
            files: [
                { label: 'DocsSearch.php', language: 'php', snippet: 'source/docs-search-php.txt' },
                { label: 'DocsSearch.twig', language: 'twig', snippet: 'source/docs-search-twig.txt' },
                { label: 'DocsSidebar.php', language: 'php', snippet: 'source/docs-sidebar-php.txt' },
                { label: 'DocsSidebar.twig', language: 'twig', snippet: 'source/docs-sidebar-twig.txt' }
            ]
        }) }}
    </div>

    <div style="position:relative;flex:1;min-width:0">
        {{ component('DocsPage', {
            docPath: route.path,
            basePath: basePath,
            manifest: manifest,
            cachePath: cachePath
        }) }}
        {{ component('ViewSource', {
            files: [
                { label: 'DocsPage.php', language: 'php', snippet: 'source/docs-page-php.txt' },
                { label: 'DocsPage.twig', language: 'twig', snippet: 'source/docs-page-twig.txt' }
            ]
        }) }}
    </div>

    {{ component('TableOfContents', { headings: docsHeadings ?? [] }) }}
</div>
```

Note: The DocsPage component is wrapped in a new div with `flex:1;min-width:0` to preserve the existing flex layout behavior while adding `position:relative` for ViewSource positioning. The sidebar gets a single ViewSource showing both DocsSearch and DocsSidebar files.

- [ ] **Step 2: Verify docs page ViewSource buttons**

Open http://localhost:8080/docs/getting-started/installation and check:
- ViewSource button visible at bottom-right of sidebar
- ViewSource button visible at bottom-right of docs content area
- Clicking opens modal with the correct component tabs
- Tab switching loads the correct source files

- [ ] **Step 3: Commit**

```bash
cd /Users/smyr/Sites/gbits/preflow-website
git add "app/pages/docs/[...path].twig"
git commit -m "feat: add ViewSource buttons to docs page components"
```

---

### Task 8: Polish and Final Verification

- [ ] **Step 1: Cross-browser check**

Open the homepage and docs in the dev server. Test:
- Modal opens and closes cleanly
- Tabs switch and lazy-load content
- Tooltip appears on hover, disappears on leave
- Escape key closes modal
- Backdrop click closes modal
- Mobile viewport (resize browser to ~375px): modal goes near-full-width, code scrolls horizontally if needed

- [ ] **Step 2: Fix any styling issues found**

Common things to watch for:
- ViewSource button overlapping section content at small viewports
- Modal code block overflowing its container
- Tooltip getting cut off at the edge of the viewport on the right-most sections
- Z-index conflicts with the navigation bar

- [ ] **Step 3: Final commit**

Only if step 2 required changes:

```bash
cd /Users/smyr/Sites/gbits/preflow-website
git add -A
git commit -m "fix: polish view-source styling and edge cases"
```
