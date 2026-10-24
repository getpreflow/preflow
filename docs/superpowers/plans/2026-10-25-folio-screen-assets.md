# Folio Screen Assets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extensions ship admin JS/CSS in their own `assets/` folder; screens and form panels declare what they need; Folio serves the files and includes them with versioned URLs and the CSP nonce. Released as v0.30.0.

**Architecture:** A resolver (`ExtensionAssets`) maps `(extension, path)` to a file inside an enabled extension's `assets/` folder and builds versioned URLs; a small controller serves them under `{prefix}/_assets/x/{extension}/{...path}`. A second resolver (`PageAssets`) turns a page's declared entries (own files, `@folio/…`, `@other/…`) into tags the admin layout prints. `ScreenView`, `#[FolioScreen]` and form panels gain an `assets` list.

**Tech Stack:** PHP 8.4, Twig, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-25-folio-screen-assets-design.md`

## Global Constraints

- URL: `{prefix}/_assets/x/{extension}/{path}`; `path` may contain subfolders.
- Served only for enabled extensions, only real paths inside `{ExtensionDefinition::$folder}/assets`, only types `css`, `js`, `mjs`, `map`, `svg`, `png`, `webp`, `woff2`, `json`; headers `Content-Type` (correct per type), `X-Content-Type-Options: nosniff`, `Cache-Control: public, max-age=31536000, immutable`; everything else 404.
- Versioned URLs `?v=` + first 12 hex of `hash_file('xxh3', …)`, as `AdminAssetUrls` does.
- Entries: `'file.js'` (own extension), `'@folio/<name>'` (Folio's `AdminAssets` allowlist; `@folio/trix` = `trix.css` + `trix.js`), `'@<extension>/<path>'` (another enabled extension). Missing file / unknown name → `ExtensionException` naming the owner (screen method or panel).
- Tags: `.css` → `<link rel="stylesheet">` in `<head>`; `.js` → `<script src defer nonce>`; `.mjs` → `<script type="module" nonce>`; other types not auto-included. `@folio/htmx.js` also emits the existing `htmx-config` meta. Order kept, duplicates dropped, attribute-level entries first.
- `folio_extension_asset(string $extension, string $path): string` Twig function; `<meta name="folio-extension-assets" content="{prefix}/_assets/x/{extension}/">` when an extension screen renders.
- `editor_assets` keeps working unchanged for Folio's own pages. Strict nonce CSP: no inline scripts/styles added; `CspGuardTest`, `AdminJsTest` stay green.
- Gates: `vendor/bin/phpunit` (baseline 2831 tests, 99 PHPUnit deprecations), `composer test:mysql`, smeyer.me and smart-stack.works suites. Release only with the owner's go-ahead.

## Review Focus

- Path escapes: `../`, `%2e%2e%2f`, `..%2f`, an absolute path, a backslash, NUL, and a symlink inside `assets/` pointing outside → 404, never file contents (tests in Task 1).
- A disabled extension, or an extension name that is not a known extension (`../app`) → 404 (Task 1).
- A screen whose `#[FolioScreen(assets:)]` names a file that was deleted after deploy → a clear `ExtensionException` naming the screen, not a broken page with a 404 tag (Task 2).
- The same file listed in the attribute and the view → included once, in attribute order (Task 2).
- An extension asset URL changes when the file content changes (cache bust) (Task 1).

---

### Task 1: Serve extension assets

**Files:**
- Create: `packages/folio/src/Extension/ExtensionAssets.php`, `packages/folio/src/Http/ExtensionAssetController.php`
- Modify: `packages/folio/src/Routing/FolioRoutes.php` (route, no auth middleware, like `_assets/{file}`; register it before the generic `{type}` routes), `packages/folio/src/FolioServiceProvider.php` (bind both; Twig function `folio_extension_asset`)
- Test: `packages/folio/tests/Extension/ExtensionAssetsTest.php`, `packages/folio/tests/Http/ExtensionAssetControllerTest.php`; route table rows wherever tests enumerate admin routes (e.g. `packages/rbac/tests/TwoRolesTest.php`, `FolioPermissionMiddlewareTest`) — the route is public, like `_assets`.

**Interfaces — Produces:**
- `ExtensionAssets::__construct(ExtensionRegistry $extensions, string $prefix)`
- `ExtensionAssets::path(string $extension, string $path): ?string` — absolute real path or null (all Global Constraints rules).
- `ExtensionAssets::url(string $extension, string $path): string` — versioned URL; throws `ExtensionException` when `path()` is null.
- `ExtensionAssets::base(string $extension): string` — `{prefix}/_assets/x/{extension}/`.
- `ExtensionAssetController::serve(ServerRequestInterface $request): ResponseInterface` (route attributes `extension`, `path`).

- [ ] Tests first: serves a root file and a `vendor/lib/x.js` subfolder file with the right content type and the three headers; 404 for every Review Focus path escape, a `.php` and a `.twig` file inside `assets/`, a disabled extension, an unknown extension name; `url()` carries `?v=<12 hex>` and changes after the file is rewritten; `folio_extension_asset('shop', 'shop.css')` renders that URL. Fixture: add `assets/shop.css`, `assets/shop.js`, `assets/vendor/lib/lib.js` to `tests/Fixtures/extensions/Shop/` (and a symlink created in the test's temp copy for the symlink case). → FAIL → implement → PASS.
- [ ] Commit `feat(folio): serve extension admin assets`.

### Task 2: Screens declare assets

**Files:**
- Create: `packages/folio/src/Extension/Screen/PageAssets.php`
- Modify: `src/Extension/Screen/ScreenView.php` (`assets` argument), `src/Extension/Attributes/FolioScreen.php` (`assets` argument), `src/Extension/Screen/ScreenRegistry.php` (keep `assets` per screen; validate entries are non-empty strings at boot), `src/Extension/Screen/ScreenDispatcher.php` (resolve and pass to the layout), `templates/admin/_layout.twig` (print tags and the meta)
- Test: `packages/folio/tests/Extension/PageAssetsTest.php`, `tests/Extension/ScreenTest.php`

**Interfaces:**
- Consumes: Task 1 `ExtensionAssets::url()`, `::base()`; existing `AdminAssets::get()` and `AdminAssetUrls::__invoke()` for `@folio/…`.
- Produces:
  - `new ScreenView(string $template, array $data = [], array $assets = [])` (`public array $assets`).
  - `#[FolioScreen(..., array $assets = [])]` (last argument).
  - `PageAssets::__construct(ExtensionAssets $ext, AdminAssets $folio, AdminAssetUrls $folioUrls)`
  - `PageAssets::resolve(string $extension, list<string> $entries, string $owner): array{styles: list<string>, scripts: list<string>, modules: list<string>, htmx: bool}` — URLs, order kept, deduped; throws `ExtensionException` naming `$owner`.
  - Layout variables: `page_assets` (that shape) and `extension_assets_base` (string|null).

- [ ] Tests first: own file, `@folio/htmx.js` (sets `htmx`), `@folio/trix` (one style + one script), `@other/file.js`, unknown `@folio/nope` and a missing own file → `ExtensionException` with the owner text; attribute + view merge order and dedup; `.mjs` lands in `modules`; a rendered screen (through the dispatcher, real Twig) has the `<link>` in `<head>`, `<script … defer nonce="…">`, the `htmx-config` meta when htmx is requested, and the `folio-extension-assets` meta; a Folio page without screen assets renders exactly as before (characterisation of `_layout.twig` head). → FAIL → implement → PASS.
- [ ] Commit `feat(folio): screens declare their assets`.

### Task 3: Form panels declare assets

**Files:**
- Modify: `packages/folio/src/Http/AdminController.php` (`recordExtras()`: collect each rendered panel `ScreenView`'s `assets`, resolved with `PageAssets` against the panel's extension; merge into the form's `page_assets`), `templates/admin/form.twig` / `_layout.twig` if needed
- Test: `packages/folio/tests/Extension/CustomizationTest.php` (or the existing FormPanel tests)

**Interfaces:**
- Consumes: Task 2 `PageAssets::resolve()`, `ScreenView::$assets`; `Customizations` must expose each panel's extension name (add it next to `label`/`position` where panels are collected).
- Produces: the edit form includes panel assets once, after Folio's own `editor_assets`.

- [ ] Tests first: a `#[FormPanel]` returning `new ScreenView('@shop/panel.twig', [], ['panel.js'])` → the edit page has that script tag once; two panels requesting the same file → once; a missing file → `ExtensionException` naming the panel method; the create form (no panels) is unchanged. → FAIL → implement → PASS.
- [ ] Commit `feat(folio): form panels declare their assets`.

### Task 4: Docs, demo and release prep

**Files:**
- Modify: `packages/folio/README.md` (Screens → "Screen assets": folder, `assets:` on `FolioScreen`/`ScreenView`/panels, `@folio/…` names list, `folio_extension_asset()`, the meta, a Mermaid lazy-load example; `editor_assets` marked Folio-internal), `ROADMAP.md` (0.30.0), `packages/folio/composer.json` constraints if anything new is used from other packages (likely none)
- Create: `docs/superpowers/plans/2026-10-25-release-0.30-checklist.md` (shape of the 0.29 checklist)
- Test: an integration test through the real app (`FolioAppTest` style) where the Shop fixture screen uses one CSS and one JS file: page renders, the asset URL returns 200 with the file, CSP header present and `CspGuardTest`-style checks find no inline script/style.

- [ ] Test → implement → full suite, `composer test:mysql`, smeyer.me and smart-stack.works suites → record counts in the checklist → commit `chore: prepare 0.30.0`. No merge, push or tag.
