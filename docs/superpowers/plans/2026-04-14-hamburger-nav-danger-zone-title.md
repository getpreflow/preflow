# Hamburger Nav + Danger Zone Title Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a mobile hamburger nav with slide-down panel and a bold "ERROR BOUNDARIES" title to the Danger Zone section.

**Architecture:** Pure CSS/HTML/JS additions to two existing Twig templates. No new files, no PHP changes. The hamburger uses a `.nav-open` class toggle with CSS transitions. The Danger Zone title is static HTML with CSS.

**Tech Stack:** Twig templates, CSS custom properties, vanilla JS

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `app/Components/Navigation/Navigation.twig` | Modify | Add hamburger button HTML, mobile panel markup, CSS rules, JS toggle |
| `app/pages/index.twig` | Modify | Add title block HTML + CSS inside Danger Zone section |

---

### Task 1: Hamburger Button + Mobile Panel HTML

**Files:**
- Modify: `app/Components/Navigation/Navigation.twig:1-30` (template section)

- [ ] **Step 1: Add hamburger button to nav bar**

Insert the hamburger button after `.nav-links` and before `.nav-actions` in `Navigation.twig`. The button is hidden on desktop and visible on mobile.

```twig
<button class="nav-hamburger" aria-label="Toggle menu" aria-expanded="false">
    <span class="nav-hamburger-bar"></span>
    <span class="nav-hamburger-bar"></span>
    <span class="nav-hamburger-bar"></span>
</button>
```

Insert this between the closing `</div>` of `.nav-links` (line 21) and the opening `<div class="nav-actions">` (line 22).

- [ ] **Step 2: Add mobile panel markup**

Insert the mobile panel after the closing `</div>` of `.nav-inner` (line 30), but still inside the component's root element. It duplicates the links and actions for mobile layout:

```twig
<div class="nav-mobile-panel">
    <div class="nav-mobile-links">
        {% for item in items %}
            <a href="{{ item.path }}" class="nav-mobile-link{{ item.active ? ' nav-mobile-link--active' : '' }}">
                {{ item.label }}
            </a>
        {% endfor %}
    </div>
    <div class="nav-mobile-actions">
        {{ component('ThemeToggle') }}
        <a href="https://github.com/getpreflow/preflow" class="nav-github" target="_blank" rel="noopener">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
            </svg>
        </a>
    </div>
</div>
```

- [ ] **Step 3: Verify dev server renders the page without errors**

Run: `cd /Users/smyr/Sites/gbits/preflow-website && php preflow serve`

Open `http://localhost:8080` in browser. Confirm the page loads. The hamburger and panel won't be styled yet but should be present in DOM on mobile viewport.

- [ ] **Step 4: Commit**

```bash
cd /Users/smyr/Sites/gbits/preflow-website
git add app/Components/Navigation/Navigation.twig
git commit -m "feat(nav): add hamburger button and mobile panel markup"
```

---

### Task 2: Hamburger CSS

**Files:**
- Modify: `app/Components/Navigation/Navigation.twig:32-106` (CSS section inside `{% apply css %}`)

- [ ] **Step 1: Add hamburger button styles**

Add these rules inside the `{% apply css %}` block, before the closing `{% endapply %}`:

```css
.nav-hamburger {
    display: none;
    flex-direction: column;
    gap: 5px;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0.5rem;
    margin-left: auto;
}

.nav-hamburger-bar {
    display: block;
    width: 20px;
    height: 2px;
    background: var(--accent);
    border-radius: 1px;
    transition: transform 0.25s, opacity 0.25s;
}

.nav-open .nav-hamburger-bar:nth-child(1) {
    transform: translateY(7px) rotate(45deg);
}

.nav-open .nav-hamburger-bar:nth-child(2) {
    opacity: 0;
}

.nav-open .nav-hamburger-bar:nth-child(3) {
    transform: translateY(-7px) rotate(-45deg);
}
```

- [ ] **Step 2: Add mobile panel styles**

Add these rules right after the hamburger styles:

```css
.nav-mobile-panel {
    display: none;
    flex-direction: column;
    background: var(--bg-primary);
    border-bottom: 1px solid var(--border);
    overflow: hidden;
    max-height: 0;
    transition: max-height 0.3s ease;
}

.nav-open .nav-mobile-panel {
    display: flex;
    max-height: 20rem;
}

.nav-mobile-links {
    display: flex;
    flex-direction: column;
    padding: 0.75rem 2rem;
}

.nav-mobile-link {
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.9375rem;
    padding: 0.625rem 0.75rem;
    border-left: 2px solid transparent;
    transition: color 0.15s, border-color 0.15s;
}

.nav-mobile-link:hover {
    color: var(--text-primary);
}

.nav-mobile-link--active {
    color: var(--accent);
    border-left-color: var(--accent);
    font-weight: 600;
}

.nav-mobile-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 2rem;
    border-top: 1px solid var(--border);
}
```

- [ ] **Step 3: Update mobile media query**

Replace the existing `@media (max-width: 768px)` block (which only hides `.nav-links`) with:

```css
@media (max-width: 768px) {
    .nav-links {
        display: none;
    }

    .nav-actions {
        display: none;
    }

    .nav-hamburger {
        display: flex;
    }

    .nav-mobile-panel {
        display: flex;
        max-height: 0;
    }

    .nav-open .nav-mobile-panel {
        max-height: 20rem;
    }
}
```

Note: `.nav-actions` is hidden on mobile because theme toggle and GitHub link move into the mobile panel. The hamburger button takes over the right side of the nav bar.

- [ ] **Step 4: Test in browser at mobile viewport**

Open `http://localhost:8080`, resize to below 768px. Confirm:
- Nav links and actions are hidden
- Hamburger (3 green bars) is visible on the right
- Panel is not visible yet (no JS toggle wired up)

- [ ] **Step 5: Commit**

```bash
cd /Users/smyr/Sites/gbits/preflow-website
git add app/Components/Navigation/Navigation.twig
git commit -m "style(nav): add hamburger and mobile panel CSS with transitions"
```

---

### Task 3: Hamburger JS Toggle

**Files:**
- Modify: `app/Components/Navigation/Navigation.twig` (add `<script>` block after `{% endapply %}`)

- [ ] **Step 1: Add the toggle script**

After the closing `{% endapply %}` tag at the end of `Navigation.twig`, add:

```html
<script>
(function() {
    const nav = document.querySelector('.nav-inner');
    const btn = nav?.querySelector('.nav-hamburger');
    if (!nav || !btn) return;

    btn.addEventListener('click', function() {
        const open = nav.classList.toggle('nav-open');
        btn.setAttribute('aria-expanded', open);
    });

    nav.closest('nav')?.querySelector('.nav-mobile-links')?.addEventListener('click', function(e) {
        if (e.target.closest('.nav-mobile-link')) {
            nav.classList.remove('nav-open');
            btn.setAttribute('aria-expanded', 'false');
        }
    });
})();
</script>
```

- [ ] **Step 2: Test hamburger interaction**

Open `http://localhost:8080` at mobile viewport (<768px). Confirm:
- Tapping hamburger opens the panel (links slide down, bars animate to X)
- Tapping X closes the panel
- Tapping a link closes the panel
- Desktop (>768px): hamburger not visible, nav links display normally

- [ ] **Step 3: Commit**

```bash
cd /Users/smyr/Sites/gbits/preflow-website
git add app/Components/Navigation/Navigation.twig
git commit -m "feat(nav): add JS toggle for hamburger menu open/close"
```

---

### Task 4: Danger Zone Title

**Files:**
- Modify: `app/pages/index.twig:209-210` (HTML, inside danger zone details)
- Modify: `app/pages/index.twig:519-524` (CSS, danger zone section)

- [ ] **Step 1: Add title HTML**

In `app/pages/index.twig`, insert the title block between the closing `</summary>` tag (line 208) and the opening `<div class="danger-zone-content">` (line 210):

```html
        <div class="danger-zone-header">
            <h2 class="danger-zone-title">Error Boundaries</h2>
            <p class="danger-zone-subtitle">What happens when components fail</p>
        </div>
```

- [ ] **Step 2: Add title CSS**

In the `{% apply css %}` block, add these rules after `.danger-zone[open] .danger-zone-line` (after line 517) and before `.danger-zone-content`:

```css
.danger-zone-header {
    margin-top: 2rem;
    margin-bottom: 2rem;
}

.danger-zone-title {
    font-size: 2.5rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: -0.02em;
    line-height: 1;
    color: rgb(224, 108, 117);
}

.danger-zone-subtitle {
    font-size: 0.875rem;
    color: var(--text-secondary);
    letter-spacing: 0.05em;
    margin-top: 0.5rem;
}
```

- [ ] **Step 3: Add mobile responsive rule**

Inside the existing `@media (max-width: 768px)` block for the danger zone (which contains `.danger-zone-content { flex-direction: column; }`), add:

```css
.danger-zone-title {
    font-size: 1.75rem;
}
```

- [ ] **Step 4: Test in browser**

Open `http://localhost:8080`, scroll to Danger Zone, click to expand. Confirm:
- "ERROR BOUNDARIES" appears in large bold red text, left-aligned
- "What happens when components fail" appears below in muted text
- The two content columns follow below
- On mobile (<768px): title scales down to 1.75rem, columns stack

- [ ] **Step 5: Commit**

```bash
cd /Users/smyr/Sites/gbits/preflow-website
git add app/pages/index.twig
git commit -m "feat(landing): add bold ERROR BOUNDARIES title to Danger Zone section"
```
