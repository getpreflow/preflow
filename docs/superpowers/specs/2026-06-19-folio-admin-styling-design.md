# Folio Admin Styling — Design

**Date:** 2026-06-19
**Package:** `preflow/folio`
**Status:** Approved, ready for implementation plan

## Goal

Give the Folio admin (mounted at `/folio`) a very clean, simple, professional
look with dark mode baked in from the start. The admin is currently
intentionally unstyled semantic HTML; this work reshapes the layout into a
proper app shell and ships a single, hand-written stylesheet — no build step,
no external dependencies, fully drop-in.

## Decisions (locked)

| Topic | Decision |
|-------|----------|
| Aesthetic | Stripe/Notion "warm minimal" — warm-neutral grays, hairline borders, disciplined whitespace, small confident type |
| Accent | Emerald/green — the single accent (primary actions, links, focus rings, active nav). Everything else neutral |
| Scope | Reshape `_layout.twig` into a sidebar app shell; restyle dashboard, list, form; add a login screen template |
| CSS approach | One hand-written modern CSS file, CSS custom properties, **no Tailwind, no bundler, no build step** |
| Delivery | Package-owned asset route: `GET {prefix}/_assets/admin.css`, cacheable, content-hashed |
| Typography | System UI font stack — zero network fetch, native on every OS, security/privacy-friendly |
| Dark mode | Toggle that defaults to OS (`prefers-color-scheme`), persists choice in `localStorage`, no-flash inline script |
| Icons | Tiny set of inline SVGs — no icon font, no external request |

## Architecture

### Design tokens

A single token layer expressed as CSS custom properties, themed by a
`data-theme` attribute on `<html>`. Components **only read tokens** — they never
branch on theme. Dark mode redefines the *same* token names under
`[data-theme="dark"]`.

Token groups:

- **Neutrals (warm gray ramp):** `--c-bg`, `--c-surface`, `--c-surface-raised`,
  `--c-border`, `--c-text`, `--c-text-muted`
- **Accent:** `--c-accent`, `--c-accent-hover`, `--c-accent-fg`
- **State:** `--c-danger`, `--c-danger-fg`, `--c-success`, `--c-success-fg`,
  `--c-focus-ring`
- **Scale:** spacing steps, radii, font sizes, shadows, `--font-sans` (system
  stack)

Default theme follows `prefers-color-scheme`. `[data-theme="light"]` and
`[data-theme="dark"]` override explicitly when the user has chosen.

### Theme switching

- Default comes from `prefers-color-scheme` via the token definitions.
- A **no-flash inline script** in `<head>` runs before first paint: reads
  `localStorage['folio-theme']` (values `light` | `dark`); if present, sets
  `document.documentElement.dataset.theme` immediately. No flash on reload.
- A sidebar toggle (sun/moon SVG) flips `data-theme` and writes the choice to
  `localStorage`. This is the only JS Folio ships for the admin — ~15 lines,
  vanilla, no dependency.

### Layout — app shell (`_layout.twig` reshaped)

```
┌──────────┬─────────────────────────────────┐
│ SIDEBAR  │  TOPBAR: page title · actions   │
│ Folio    ├─────────────────────────────────┤
│          │                                 │
│ Dashboard│   CONTENT (max-width, centered) │
│ Pages    │                                 │
│ Articles │                                 │
│ …types   │                                 │
│ ──────── │                                 │
│ ◐ theme  │                                 │
└──────────┴─────────────────────────────────┘
```

- **Fixed left sidebar:** wordmark, content-type nav (active item = emerald),
  theme toggle pinned to the bottom.
- **Topbar:** page heading plus contextual actions (e.g. "New" on a list;
  "Save"/"Delete" on a form). Exposed to child templates via a Twig block so
  each page declares its own actions.
- **Content area:** max-width container, comfortable line length.
- **Responsive:** on narrow screens the sidebar collapses to a top bar / drawer
  using a CSS-only mechanism (checkbox-hack or `<details>`) — no JS needed for
  navigation. The theme toggle's JS is the only script.

### Components

- **Dashboard (`dashboard.twig`):** content types as a clean card grid — label,
  record count, arrow affordance — instead of a bare link list.
- **List (`list.twig`):** real data table — hairline rows, hover highlight,
  right-aligned row actions (Edit / Delete), and an empty state ("No records
  yet — Create one").
- **Form (`form.twig`):** pure CSS over the existing `preflow/form` hooks
  (`form-group`, `form-required`, `form-help`, `form-error`, `has-error`, and
  the group variants). Single column, labels above inputs, visible focus rings,
  inline error styling, sticky footer action bar (Save primary / Cancel ghost).
  **No changes to the form package** — styling rides on existing classes.
- **Login (`login.twig`, new `@folio` template):** centered card, wordmark,
  single email/password form, emerald submit. Styled now; auth wiring lands
  later and is out of scope here.
- **Buttons:** primary (emerald), secondary (neutral surface + border), ghost,
  danger.
- **Flash/alert banners:** success and error variants for post-action feedback.

### Delivery

- **CSS route:** a package-owned controller action serves
  `GET {prefix}/_assets/admin.css` with `Content-Type: text/css` and long-lived
  cache headers. The `<link>` in `_layout.twig` carries a content hash in the
  query string (`admin.css?v=<hash>`) so it caches hard but busts on change.
- **Source:** a single `.css` file shipped inside the package. No build, no
  publish step, no symlink into the host app's `public/`.
- **Icons:** inline SVGs in the templates.
- **Overridability:** every template stays overridable via the `@folio`
  namespace (host apps can drop replacements in `resources/folio/`).

## Testing

- Feature test: the asset route returns `200`, `Content-Type: text/css`, and
  cache headers.
- Template render tests:
  - shell renders the sidebar, content-type nav, and theme toggle;
  - list renders a table and the empty state;
  - form applies error classes when a field `has-error`;
  - login template renders its card and form.
- Static check on the stylesheet: it defines both the base `:root` token set and
  a `[data-theme="dark"]` override set (dark mode is actually present, not a
  stub).

## Out of scope (YAGNI)

- Actual authentication logic (login is styled, not wired).
- Media / asset manager UI.
- Rich-text / WYSIWYG editor.
- i18n of the admin chrome.
- Any build tooling (npm, Vite, PostCSS, etc.).

## Affected files (anticipated)

- `packages/folio/templates/admin/_layout.twig` — reshaped into app shell
- `packages/folio/templates/admin/dashboard.twig` — card grid
- `packages/folio/templates/admin/list.twig` — data table + empty state
- `packages/folio/templates/admin/form.twig` — styled form layout
- `packages/folio/templates/admin/login.twig` — **new**
- `packages/folio/src/Http/AdminController.php` (or a new asset controller) —
  asset route serving `admin.css`
- `packages/folio/resources/assets/admin.css` (or similar) — **new** stylesheet
- Route registration for `{prefix}/_assets/admin.css`
- Tests under `packages/folio/tests/`
