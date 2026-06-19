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

### Delivery — designed for no lock-in

The host app has **no asset-publishing system yet**, but one is anticipated
(Yii2-style publishing from package source dirs to a public web root, plus
private/public storage). The delivery design must not preclude that, and must
not overload the existing seams (`asset_url()`, `AssetCollector`) in a way that
boxes the future system in.

- **Source of truth — a real file.** The stylesheet lives as a genuine `.css`
  file inside the package (e.g. `packages/folio/assets/admin.css`), authored as
  CSS, *not* embedded in a PHP heredoc. This file is publish-ready: a future
  asset publisher can copy/symlink it to a public web root unchanged.
- **Served now via a package-owned route.** A small `AssetController` serves
  `GET {prefix}/_assets/admin.css` by **reading that file from disk** and
  returning it with `Content-Type: text/css; charset=UTF-8` and long-lived
  cache headers. No build, no publish step, no symlink required today.
- **One URL seam, centralized.** The `<link>` href is produced in exactly one
  place — a single Twig global registered by `FolioServiceProvider` (e.g.
  `folio_admin_css_url`) resolving to `{prefix}/_assets/admin.css?v=<hash>`.
  Templates never construct the URL themselves. When a publishing system later
  lands, only this one resolver changes (to point at the published path); the
  templates and the CSS file are untouched.
  - Do **not** repurpose `asset_url()` for this. `asset_url()` is the seam the
    future publisher will own; Folio uses its own resolver so the two don't
    collide.
- **Cache busting.** `<hash>` is a content hash of the CSS file (xxh3 of file
  contents), computed once at boot and exposed via the global. The route can
  send `Cache-Control: public, max-age=…, immutable` because the URL is
  versioned.

### CSP / nonce handling

`AssetCollector` emits CSP nonces, which implies a nonce-based policy. Two
consequences for this work:

- **Stylesheet link:** an external same-origin `<link>` only loads if the
  policy's `style-src` allows `'self'`. **Verify the actual CSP** the framework
  emits for admin responses. If `style-src` is nonce-only without `'self'`,
  either add `'self'` for the admin area or fall back to inlining the CSS via
  `AssetCollector::addCss()` (nonced). Resolve this explicitly during
  implementation — do not assume the link "just works".
- **Theme scripts:** the no-flash head script and the toggle handler are
  registered through the asset system (`addJs(Head)` / `{% apply js('head') %}`
  and body JS) so they carry the request nonce. No raw inline `<script>` without
  a nonce.

### Other delivery notes

- **Icons:** inline SVGs in the templates — no icon font, no external request.
- **Overridability:** every template stays overridable via the `@folio`
  namespace (host apps can drop replacements in `resources/folio/`).

## Testing

- Feature test: the asset route returns `200`, `Content-Type: text/css`, the
  cache headers, and the actual CSS file's contents (served from disk).
- Test: the versioned URL global is registered and the version changes when the
  CSS file's contents change.
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
- `packages/folio/src/Http/AssetController.php` — **new**, serves `admin.css`
  from disk with cache headers
- `packages/folio/assets/admin.css` — **new** stylesheet (real file, the source
  of truth)
- `packages/folio/src/Routing/FolioRoutes.php` — add `{prefix}/_assets/admin.css`
  route entry
- `packages/folio/src/FolioServiceProvider.php` — register the route + the
  `folio_admin_css_url` Twig global (with content-hash version)
- Tests under `packages/folio/tests/`
