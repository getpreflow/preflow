# Folio Screen Assets — Design

**Date:** 2026-10-25
**Status:** Draft for the owner's approval.
**Context:** Phase 2, step 3 of the BG Genius on Folio plan
(`bggenius-preflow/docs/superpowers/specs/2026-10-24-bggenius-on-folio-design.md`).
Current framework version: v0.29.0.

## Why

`#[FolioScreen]` gives an extension a full admin page, but there is no documented way for
that page to load its own JS and CSS. Folio's layout loads extra files from the template
variable `editor_assets`, which Folio's own controllers fill with names from the
`AdminAssets` allowlist (`{prefix}/_assets/{file}`, flat names, registered in a provider's
`boot()`). A screen can only piggyback on that by copying an internal convention.

BG Genius's flow editor (Phase 3) is the first real screen. It needs `flow-editor.js`,
`step-editor.js`, `quiz-model.js`, `md-editor.js`, its CSS, a vendored Mermaid bundle and
htmx — under the strict nonce CSP (`script-src 'self' 'nonce-…'`, `style-src` likewise).

## Goals

- An extension ships its admin files in its own folder; Folio serves them. No provider
  code, no manual allowlist entries.
- A screen (and a form panel) declares which files it needs; the layout includes them with
  cache-busting URLs and the page's nonce.
- Vendored libraries in subfolders work (`vendor/mermaid/mermaid.min.js`).
- Folio's own shared files (htmx, the rich-text editor) can be requested by name.
- `editor_assets` keeps working for Folio itself; screens never need it.

Non-goals: a bundler or build step; frontend (public site) assets; serving files of
disabled extensions.

## Design

### Where files live and how they are served

- Each extension may have an `assets/` folder next to its `Extension.php`
  (`ExtensionDefinition::$folder . '/assets'`), for app extensions (`app/Folio/<Name>/`) and
  Composer-installed ones alike.
- New route `GET {prefix}/_assets/x/{extension}/{path...}` (catch-all path, no auth — like
  `_assets/{file}` today, since the files are code, not content). It serves a file only if:
  the extension is enabled; the resolved real path lies inside that extension's `assets/`
  folder (no `..`, no symlink escape); the file type is one of: `css`, `js`, `mjs`, `map`,
  `svg`, `png`, `webp`, `woff2`, `json`. Correct `Content-Type`, `X-Content-Type-Options:
  nosniff`, and `Cache-Control: public, max-age=31536000, immutable`.
- URLs carry a content hash (`?v=…`), like `folio_asset()` does today, so the immutable
  cache busts on every change.

### Declaring what a page needs

- `ScreenView` gains a third argument: `new ScreenView($template, $data, assets: [...])`.
- `#[FolioScreen(..., assets: [...])]` sets files every method of that screen gets;
  a `ScreenView`'s list is added to them (order kept, duplicates dropped).
- Entries:
  - `'flow-editor.js'`, `'vendor/mermaid/mermaid.min.js'` — a file in this extension's
    `assets/` folder;
  - `'@folio/htmx.js'`, `'@folio/trix'` — one of Folio's shared files (names from the
    existing `AdminAssets` allowlist; `@folio/trix` stands for its JS + CSS pair);
  - `'@other-extension/file.js'` — a file of another enabled extension (rare; allowed so
    extensions can share a library).
- A missing file or unknown name is an `ExtensionException` at render time naming the
  screen method — like a missing template today. In debug mode the check runs on every
  render; in production it is the same check (cheap: a `stat`).
- `.css` → `<link rel="stylesheet">` in `<head>`; `.js` → `<script src defer nonce>`;
  `.mjs` → `<script type="module" nonce>`. Other types are not auto-included (they are for
  URLs, see below).
- Form panels (`#[FormPanel]` returning a `ScreenView`) may declare assets the same way;
  the edit form's layout includes them. This is how a type customization ships JS for its
  panel.
- `@folio/htmx.js` in the list also emits the existing `htmx-config` meta (inline-style
  nonce, no script tags), exactly as `editor_assets` does today.

### URLs inside templates and JS

- Twig function `folio_extension_asset(string $extension, string $path): string` returns the
  versioned URL (for lazy-loading Mermaid, images in a screen, a JSON file).
- The layout exposes the current extension's asset base as
  `<meta name="folio-extension-assets" content="…">` when a screen of an extension renders,
  so scripts can build URLs for lazy loads without inline config.

### CSP

- Included files are same-origin, so `'self'` already allows them; the layout still adds
  `nonce="{{ csp_nonce() }}"` to every `<script>` it emits (harmless, and keeps working if a
  site tightens `script-src` to nonces only).
- Inline `<script>`/`<style>` in screen templates stay forbidden by the CSP as today; the
  docs point to this mechanism instead. `CspInspector` (devtools) already flags them.

### Documentation

- Folio README, "Screens": a subsection "Screen assets" with the folder convention, the
  `assets:` argument, the `@folio/…` names, `folio_extension_asset()`, and a Mermaid
  example.
- `editor_assets` is described as Folio-internal.

## Tests (by the builder, following existing patterns)

- Serving: enabled vs disabled extension; path traversal (`../`, encoded `%2e%2e`,
  absolute, symlink pointing outside) → 404; disallowed extension (`.php`, `.twig`) → 404;
  headers and content types; subfolder files.
- Declaring: attribute-level + view-level merge and order; `@folio/htmx.js` emits the meta;
  unknown/missing file → `ExtensionException`; CSS in head, JS deferred with nonce, `.mjs`
  as module; form panel assets appear on the edit form.
- `folio_extension_asset()` URL with hash; the hash changes when the file changes.
- The demo extension (`tests/Support` / `examples`) gets a screen using one CSS and one JS
  file, rendered through the real app (`FolioAppTest` style) under strict CSP checks
  (`CspGuardTest`).

## Decided here (my calls, for the owner to check)

- Route `{prefix}/_assets/x/{extension}/{path}` rather than registering every file into the
  flat allowlist: subfolders and vendored libraries work without renaming, and nothing has
  to run at boot.
- Assets declared per screen/view (not globally per extension), so the editor's JS only
  loads on the editor.
- No auth on asset files (as today for `_assets`): they are code shipped with the
  extension. Anything secret belongs in a screen endpoint behind its permission.
- File types limited to the list above; `.php`/`.twig`/`.json` outside `assets/` are never
  reachable.

## Size

Small: one route + controller, `ScreenView`/`FolioScreen`/`FormPanel` additions, a layout
block, one Twig function, README section, tests. One plan of ~4 tasks; release as 0.30.0.
