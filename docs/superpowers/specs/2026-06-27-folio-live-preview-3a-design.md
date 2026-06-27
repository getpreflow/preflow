# Folio Live Preview 3a — Draft-aware Preview in a Resizable Iframe — Design

**Date:** 2026-06-27
**Package:** `preflow/folio`
**Status:** Approved.
**Roadmap item:** #3 "Live preview" from
`2026-06-18-preflow-folio-walking-skeleton-design.md`. That item bundles
draft-aware rendering + surgical fragment swap + resizable iframe; per the
brainstorm it is **decomposed into two cycles**:
- **3a (THIS spec):** draft-aware full-page preview rendered into a resizable,
  viewport-simulating iframe, toggled Craft-style from the edit form.
- **3b (later):** surgical per-field fragment swap (postMessage, re-render only
  the changed field, no full reload). Additive on top of 3a.

## Goal

From the page edit/create form, a **Preview** button opens a full-screen split —
the form on the left, a resizable device-framed iframe on the right — that
renders the page's **in-progress, unsaved** values through the *real* frontend
template, updating debounced as the author types. This fixes the two Crelish
failures the roadmap calls out: **style isolation** (iframe, so admin CSS can't
bleed into the preview and vice-versa) and **true responsive preview**
(a resizable iframe simulates viewport widths — Shadow DOM cannot).

## Why this fits the existing pipeline

The public frontend already renders a record with no preview-specific code:
`FrontendController::show` does
`engine->render('@folio/frontend/page.twig', ['record' => $record->toArray(),
'rendered' => $records->renderedMap($record)])`. Preview reuses that exact path
with an **in-memory `DynamicRecord` built from the form POST** — no new
`FieldType` method, no change to the frontend renderer. The only new pieces are
a side-effect-free preview endpoint and the admin-side toggle/iframe JS.

## Architecture

### Server — preview endpoint

New `Http/PreviewController` with one action handling both routes:
- `POST {prefix}/{type}/preview` (new, unsaved record — no id)
- `POST {prefix}/{type}/{id}/preview` (editing an existing record)

`preview(ServerRequestInterface): ResponseInterface`:
1. `$type = attr('type')`; `404` if `!catalog->has($type)`.
2. **`404` unless `$type` is the frontend type** (`page` — the type
   `FrontendResolver` serves). Preview only applies to frontend-renderable types
   in 3a; `note`/`author` have no standalone page.
3. Build a **draft `DynamicRecord`** from the submitted body (see below) — never
   persisted.
4. `return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'],
   $engine->render('@folio/frontend/page.twig', ['record' => $draft->toArray(),
   'rendered' => $records->renderedMap($draft)]))`.

**Draft build (side-effect-free).** For `$id` present, load
`existing = dm->findType($type,$id)?->toArray() ?? []`; else `existing = []`.
For each field in `typeDef->fields` (skip `idField`):
- **`HandlesUpload` field:** value = `toStorage(keptPaths)` where `keptPaths` =
  the field's `fromStorage(existing[name])` minus the submitted `{name}_remove`
  entries. **No `storeUploads` call — no file writes.** Brand-new file uploads
  are intentionally NOT rendered (they only appear after Save) — the one
  documented limitation; uploading on every debounced keystroke would be wrong.
- **Any other field:** `toStorage(normalizeInput($submitted[name] ?? null,
  $fieldDef->config))` — the same storage shape `store`/`update` produce.

Then `data[idField] = $id` (or `''` for the no-id route) and
`$draft = DynamicRecord::fromArray($typeDef, $data)`.

**Crucially, preview does NOT call `saveType`** — so it skips validation
(a draft with a missing-required field still previews) and never touches storage,
and it does NOT apply the `status === 'published'` gate (so unpublished drafts
preview). Output goes through the unchanged `renderedMap`/`renderFrontend` path,
so richtext is re-sanitized and scalars escaped exactly as on the live site —
**no new XSS surface**.

> Note: the per-field normalize→toStorage loop and the kept-paths logic overlap
> with `AdminController::collectFieldData`. 3a keeps `PreviewController`
> self-contained (its own draft build) to leave the save path untouched and
> stable; a later cycle may extract a shared `DraftRecordFactory`. This small,
> deliberate duplication is called out so a reviewer doesn't read it as an
> oversight.

### Routing

Add to `FolioRoutes::admin()` `$defs`. **Order matters** (first match wins):
`POST {prefix}/{type}/preview` would otherwise be shadowed by
`POST {prefix}/{type}/{id}` (update, with `id="preview"`), so the no-id preview
route MUST be registered **before** the `update` route. The `{id}/preview` route
is a distinct 3-segment literal (no collision with the 2-segment `update` or the
literal `{id}/delete`). Concretely:
- add `['POST', $prefix.'/{type}/preview', 'preview']` before
  `['POST', $prefix.'/{type}', 'store']`;
- add `['POST', $prefix.'/{type}/{id}/preview', 'preview']` before
  `['POST', $prefix.'/{type}/{id}', 'update']`.

Both are admin POSTs and carry the form's existing `_csrf_token` (session-scoped,
valid on any admin route), so CSRF protection applies unchanged.

### Admin UI — Craft-style toggle (`admin.js` + `form.twig`)

- **Preview button.** `form.twig` renders a `data-folio-preview` button carrying
  `data-preview-url` (the type's preview route) into the layout's topbar
  `{% block actions %}` — **only when the type is previewable**. `AdminController`
  gains the frontend type (injected) and passes `previewable`/`previewUrl` into
  the form context; the button renders only for the frontend type (`page`).
- **Overlay + form reparent.** Clicking opens a full-screen overlay
  (`folio-preview`) and **moves the real `<form>` element** into its left pane
  (`appendChild` preserves the node and its listeners, so the already-initialized
  trix/matrix editors keep working). The right pane holds the iframe plus a
  viewport toolbar (Desktop 100% / Tablet 768px / Mobile 375px). A Close control
  reparents the form back to its original position and removes the overlay
  (Escape also closes — consistent with the 5b drawer).
- **Debounced render.** An `input`/`change` listener on the form, debounced
  ~400ms, serializes it with `new FormData(form)` (which includes the hidden
  `_csrf_token`), `fetch`-POSTs to `data-preview-url`, and on success sets
  `iframe.srcdoc = responseText`. `srcdoc` renders the returned full HTML
  document in an isolated iframe (style isolation); the viewport buttons set the
  iframe wrapper width (responsive simulation). Stale responses are ignored
  (latest-wins via a request counter, mirroring the 5b fetch hygiene). An initial
  render fires when the overlay opens.

### CSS (`admin.css`)

`.folio-preview` (full-viewport overlay), `.folio-preview-form` (left pane),
`.folio-preview-stage` (right pane), `.folio-preview-frame` (the iframe;
width driven by the active viewport preset, centered, with a device-frame
border), `.folio-preview-bar` (viewport toolbar + close), consistent with the
existing admin visual language.

## New / affected files

- `packages/folio/src/Http/PreviewController.php` — **new**.
- `packages/folio/src/Routing/FolioRoutes.php` — **modify** (two preview routes,
  ordered before store/update).
- `packages/folio/src/Http/AdminController.php` — **modify** (inject frontend
  type; pass `previewable`/`previewUrl` to the form context).
- `packages/folio/src/FolioServiceProvider.php` — **modify** (bind
  `PreviewController`; pass the frontend type to it and to `AdminController` —
  the same `'page'` literal `FrontendResolver` already uses).
- `packages/folio/templates/admin/form.twig` — **modify** (Preview button in the
  topbar `actions` block when previewable).
- `packages/folio/assets/admin.js` — **modify** (preview overlay: reparent,
  viewport presets, debounced fetch→srcdoc, close).
- `packages/folio/assets/admin.css` — **modify** (overlay/stage/frame styles).
- Tests (below). No new demo files required — the existing demo `page` +
  `@folio/frontend/page.twig` are previewable as-is.

## Testing

**Unit — `PreviewControllerTest`:**
- Builds an unsaved record from POST values and returns frontend HTML reflecting
  the **draft** values, while writing **nothing** to storage (assert the type's
  record count/contents are unchanged after the call).
- An existing-record preview uses the draft text but renders without persisting
  (the stored record is unchanged); asset fields fall back to saved paths.
- A draft missing a required field still previews (no validation 422); an
  unpublished/`draft` status still previews (no status gate).
- Unknown type → 404; a non-frontend type (e.g. `note`) → 404.

**Static — `admin.js` (`AdminJsTest`):** asserts the preview hooks
(`data-folio-preview`, overlay open/close, form reparent, viewport presets,
debounced `fetch`, `srcdoc`).

**Integration — `FolioAppTest` (real Twig):**
- `POST {prefix}/page/preview` with a draft `title` → `200` HTML containing the
  draft title; `queryType('page')` count is unchanged (nothing created) and the
  response is not a `302` (proves the route resolves to `preview`, not `update`).
- Create a page, then `POST {prefix}/page/{id}/preview` with a changed `title` →
  response shows the changed title; re-loading the stored record shows the
  **original** title (unchanged).
- The edit form for `page` contains the `data-folio-preview` button; the edit
  form for a non-frontend type (`note`) does not.

**Full suite:** `vendor/bin/phpunit` green (only the pre-existing PHPUnit
deprecations + 1 skip); folio + demo green under strict Twig.

## Out of scope (→ 3b / later)

Surgical per-field fragment swap (3a always re-renders the whole page document).
Previewing brand-new, not-yet-saved file uploads. Preview for non-frontend types.
Drag-to-resize the iframe (preset widths only in 3a). A shared
`DraftRecordFactory` refactor unifying preview with the save path.
