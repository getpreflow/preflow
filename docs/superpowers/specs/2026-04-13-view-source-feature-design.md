# View Source Feature — Design Spec

**Date:** 2026-04-13
**Status:** Approved
**Project:** preflow-website (`/Users/smyr/Sites/gbits/preflow-website`)

## Overview

A dogfooding showcase feature: small "view source" buttons on each section of the homepage and docs pages let visitors inspect the actual Preflow component code (PHP class + Twig template) that renders that section. Clicking opens a modal with tabbed, syntax-highlighted source code. The feature itself is built with Preflow components, demonstrating nested composition and HTMX lazy-loading.

## Principles

- **Dogfooding** — the feature showcases Preflow's component model by being built with it
- **Generic primitives** — Tooltip and Modal are reusable components, not tied to the view-source use case
- **No new attack surface** — source is served from pre-baked `.txt` snippet files, not read from disk at runtime
- **Lazy-loading** — snippet content is fetched via HTMX on first tab activation, keeping initial page weight low

## New Components

### 1. Tooltip

**Location:** `app/Components/Tooltip/`

**Props:**
- `text` (string, required) — tooltip content
- `position` (string, default: `'left'`) — placement relative to trigger. Only `'left'` is implemented initially; can be extended to `'top'`, `'right'`, `'bottom'` later.

**Behavior:**
- Preflow components are props-only (no slot/children mechanism), so Tooltip renders both the trigger and the tooltip text
- Accepts an `icon` prop (Lucide class name, e.g. `'icon-code'`) to render as the trigger button, plus optional `ariaLabel` for accessibility
- On `:hover` and `:focus` of the wrapper, shows a styled tooltip to the left
- Pure CSS implementation — inner `<span>` positioned absolutely with arrow via `::before` pseudo-element
- Subtle fade-in via CSS transition (`opacity` + slight `translateX`)
- No JS required

**Props (full list):**
- `text` (string, required) — tooltip content
- `position` (string, default: `'left'`)
- `icon` (string, optional) — Lucide class name for trigger button. When omitted, renders a generic `<span>` wrapper (for use with custom trigger markup via a wrapping component)
- `ariaLabel` (string, optional) — accessibility label for the trigger button

**Styling:**
- Background: `var(--bg-elevated)`
- Text: `var(--text-primary)`
- Shadow: `var(--shadow-md)`
- Border-radius: `0.375rem`
- Font-size: `0.75rem`
- White-space: `nowrap`
- Arrow/caret pointing right toward the trigger element
- Z-index above page content but below modal overlay

### 2. Modal

**Location:** `app/Components/Modal/`

**Props:**
- `id` (string, required) — unique identifier, used for `Modal.open(id)` / `Modal.close(id)`
- `title` (string, optional) — displayed in the modal header
- `tabs` (array, optional) — list of tab objects. Each: `{ label: string, id: string, url: string }`. When provided, renders a tab bar. When omitted, renders a single content area (generic use).

**Structure:**
```
backdrop (fixed overlay, semi-transparent)
  modal-container (centered, max-width 48rem, max-height 80vh)
    modal-header
      title (if provided)
      close button (X icon from Lucide: icon-x)
    tab-bar (if tabs provided)
      tab buttons (one per tab, active state styled)
    modal-body (scrollable)
      tab-panel per tab (shown/hidden by active tab)
```

**Behavior:**
- **Open:** `Modal.open(id)` — shows backdrop + container, locks body scroll (`overflow: hidden` on `<body>`)
- **Close:** `Modal.close(id)` — hides everything, restores body scroll. Triggered by: X button click, backdrop click, Escape key.
- **Tab switching:** Pure client-side JS. Clicking a tab shows its panel, hides others, updates active tab styling. On first activation of a tab, if its panel is empty and has a `data-src` URL, triggers an HTMX-style fetch to load the content (using `htmx.ajax()` or a manual fetch that swaps innerHTML).
- **Entrance animation:** Backdrop fades in, modal scales from 0.95 to 1.0 with opacity fade.
- **First tab auto-loads** on modal open.

**Styling:**
- Backdrop: `rgba(0, 0, 0, 0.6)`, z-index: 1000
- Container: `var(--bg-surface)` background, `var(--border)` border, `var(--shadow-lg)` shadow, `border-radius: 0.75rem`
- Tab bar: border-bottom `var(--border)`, active tab has `var(--accent)` bottom border (2px), inactive tabs `var(--text-muted)` color
- Close button: `var(--text-muted)`, hover `var(--text-primary)`
- Body: `overflow-y: auto`, padding `1.25rem`
- Responsive: on mobile (<640px), modal goes near-full-width with smaller padding

**JS (co-located via `{% apply js %}`):**
```javascript
window.Modal = {
    open(id) { /* show modal, lock scroll, load first tab */ },
    close(id) { /* hide modal, restore scroll */ },
    switchTab(id, tabId) { /* show panel, lazy-load if needed */ }
};
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { /* close any open modal */ }
});
```

### 3. ViewSource

**Location:** `app/Components/ViewSource/`

**Props:**
- `files` (array, required) — list of file objects. Each: `{ label: string, language: string, snippet: string }`.
  - `label`: tab title, e.g. `'Hero.php'`
  - `language`: syntax highlighting language, e.g. `'php'` or `'twig'`
  - `snippet`: path to `.txt` file relative to CodeExample's snippets dir, e.g. `'source/hero-php.txt'`
- `tooltip` (string, default: `'See how this was built'`) — tooltip text

**Behavior:**
- Renders a small Lucide `icon-code` button
- Positioned `absolute`, `bottom: 1rem`, `right: 1rem` (parent section must have `position: relative`)
- Button is wrapped in the `Tooltip` component
- On click, calls `Modal.open('vs-{uniqueId}')` where uniqueId is auto-generated (e.g. hash or counter)
- Also renders a `Modal` component instance with:
  - `id`: `'vs-{uniqueId}'`
  - `title`: `'Source Code'`
  - `tabs`: mapped from `files` prop — each tab's `url` points to the HTMX endpoint

**Button styling:**
- Size: `2rem x 2rem`
- Background: `var(--bg-card)` with `var(--border)` border
- Border-radius: `0.5rem`
- Icon color: `var(--text-muted)`, hover: `var(--accent)`
- Subtle hover shadow: `var(--shadow-sm)`
- Transition on hover (color + shadow)
- Opacity: `0.7`, hover: `1`

## HTMX Endpoint

**Route:** `app/pages/api/source.twig` (file-based route at `/api/source`)

**Method:** GET

**Query params:**
- `file` (string) — snippet path, e.g. `source/hero-php.txt`
- `lang` (string) — language for highlighting, e.g. `php`

**Response:** Renders a `CodeExample` component with the given file and language. Returns the HTML fragment (no layout wrapping — this is an HTMX partial).

**Security:**
- Validate `file` param contains no `..` path traversal
- Validate `file` param starts with `source/`
- Validate `lang` param is one of an allowed list (`php`, `twig`, `html`, `javascript`, `css`)
- Return 400 on invalid input

**Implementation:** A file-based route at `app/pages/api/source.twig` that does NOT extend `_layout.twig`. In Preflow's file-based routing, a template that doesn't extend a layout renders as a bare fragment — exactly what HTMX expects. The template checks query params, validates, and renders:
```twig
{# No {% extends %} — renders as bare HTMX fragment #}
{% if valid %}
    {{ component('CodeExample', { file: file, language: lang, title: label }) }}
{% else %}
    <p>Invalid request.</p>
{% endif %}
```

The `ComponentRenderer::renderFragment()` method already exists in Preflow for HTMX partial responses, confirming this pattern is supported.

## Snippet Files

**Location:** `app/Components/CodeExample/snippets/source/`

**Naming convention:** `{component-name}-{php|twig}.txt` (lowercase, hyphenated)

**Files to create (copy & rename from actual component source):**

Homepage sections:
- `hero-php.txt`, `hero-twig.txt`
- `feature-grid-php.txt`, `feature-grid-twig.txt`
- `feature-card-php.txt`, `feature-card-twig.txt`
- `quick-start-php.txt`, `quick-start-twig.txt`
- `architecture-diagram-php.txt`, `architecture-diagram-twig.txt`
- `package-card-php.txt`, `package-card-twig.txt`

Docs page sections:
- `docs-sidebar-php.txt`, `docs-sidebar-twig.txt`
- `docs-search-php.txt`, `docs-search-twig.txt`
- `docs-page-php.txt`, `docs-page-twig.txt`

Total: 18 snippet files.

## Integration Points

### Homepage (`app/pages/index.twig`)

Each major section gets `position: relative` (via inline style or CSS class) and a `ViewSource` call at the end:

| Section | ViewSource files |
|---------|-----------------|
| Hero | Hero.php, Hero.twig |
| FeatureGrid | FeatureGrid.php, FeatureGrid.twig, FeatureCard.php, FeatureCard.twig |
| QuickStart | QuickStart.php, QuickStart.twig |
| Code Showcase | (skip — this section already shows code examples, adding ViewSource would be redundant) |
| ArchitectureDiagram | ArchitectureDiagram.php, ArchitectureDiagram.twig |
| Package Wall | PackageCard.php, PackageCard.twig |

### Docs Page (`app/pages/docs/[...path].twig`)

| Component | ViewSource files |
|-----------|-----------------|
| DocsSidebar | DocsSidebar.php, DocsSidebar.twig |
| DocsSearch | DocsSearch.php, DocsSearch.twig |
| DocsPage | DocsPage.php, DocsPage.twig |

Note: The docs page has a 3-column flex layout. The ViewSource buttons need to be positioned relative to their respective component containers, which may need minor CSS adjustments.

## What This Does NOT Include

- No live source reading from disk — snapshots only
- No "edit source" capability — read-only viewing
- No deep-linking to specific modal/tab states
- No syntax highlighting theme switcher within the modal
- No mobile-specific ViewSource button repositioning beyond the modal going full-width
