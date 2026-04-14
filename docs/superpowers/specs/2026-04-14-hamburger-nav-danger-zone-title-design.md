# Hamburger Nav + Danger Zone Title Design

**Date:** 2026-04-14
**Project:** Preflow Website (`/Users/smyr/Sites/gbits/preflow-website`)

## Overview

Two UI enhancements to the preflow marketing website:

1. **Mobile hamburger navigation** -- slide-down panel replacing the hidden nav links at `<=768px`
2. **Danger Zone expanded title** -- bold "ERROR BOUNDARIES" heading with subtitle when the section is opened

Both changes are scoped to existing files with no new components or routes.

---

## 1. Hamburger Nav (Slide-Down Panel)

### File: `app/Components/Navigation/Navigation.twig`

**Trigger (hamburger icon):**
- Three horizontal bars built from `<span>` elements inside a button
- Hidden on desktop (`display:none` above 768px)
- Visible at `max-width: 768px`, positioned in the nav bar where links would be (right side, before actions)
- Bars are accent-colored (`var(--accent)` / `#71C674`)
- On open: top and bottom bars rotate 45deg/-45deg to form X, middle bar fades out (CSS transitions)

**Panel:**
- Sits below the nav bar, full width
- Contains: nav links stacked vertically, then a separator, then theme toggle + GitHub link
- Active link indicated with `border-left: 2px solid var(--accent)` and accent text color
- Inactive links in `var(--text-secondary)`, hover to `var(--text-primary)`
- Uses `max-height` transition for smooth open/close animation
- Background: `var(--bg-primary)` with `border-bottom: 1px solid var(--border)`

**Behavior (minimal JS in `<script>` block):**
- Hamburger button toggles a `.nav-open` class on `.nav-inner`
- Clicking a nav link removes `.nav-open`
- No external dependencies, no new JS files

**Responsive rules:**
- `>768px`: hamburger hidden, `.nav-links` flex as before (no change to desktop)
- `<=768px`: `.nav-links` hidden, hamburger visible, panel controlled by `.nav-open` class

### No changes to `Navigation.php`

The PHP component class needs no modification -- items, theme, and active state logic are unchanged.

---

## 2. Danger Zone Expanded Title

### File: `app/pages/index.twig`

**HTML (inside `<details class="danger-zone">`, before `.danger-zone-content`):**

```html
<div class="danger-zone-header">
    <h2 class="danger-zone-title">Error Boundaries</h2>
    <p class="danger-zone-subtitle">What happens when components fail</p>
</div>
```

**CSS (in the existing `{% apply css %}` block):**

- `.danger-zone-header`: margin-top ~2rem, margin-bottom ~2rem
- `.danger-zone-title`: font-size ~2.5rem (scales down on mobile), font-weight 900, uppercase, letter-spacing tight (-0.02em), color `rgb(224, 108, 117)` (danger red), line-height 1
- `.danger-zone-subtitle`: font-size ~0.875rem, color `var(--text-secondary)`, letter-spacing 0.05em, margin-top 0.5rem

**Mobile (`<=768px`):** Title scales down to ~1.75rem.

---

## Files Touched

| File | Change |
|------|--------|
| `app/Components/Navigation/Navigation.twig` | Hamburger button, mobile panel, CSS, JS toggle |
| `app/pages/index.twig` | Danger zone title block + CSS |

No new files. No PHP changes. No new dependencies.
