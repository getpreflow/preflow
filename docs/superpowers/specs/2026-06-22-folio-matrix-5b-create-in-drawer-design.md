# Folio Matrix 5b — Create-in-drawer — Design

**Date:** 2026-06-22
**Package:** `preflow/folio`
**Status:** Approved.
**Builds on:** `2026-06-19-folio-matrix-design.md` (5a core) — this is the 5b
addendum that spec named ("an 'add new' flow that opens the target type's create
view in a drawer/iframe and, on save, passes the new record's id back to the
matrix via `postMessage`"). Additive on top of 5a; no 5a behavior changes.

## Goal

From the matrix editor, an author can create a brand-new record of an allowed
type **without leaving the page being edited**. A "New" action opens the target
type's create form in a drawer (iframe). On save, the drawer hands the new
record's **id** back to the opener via `postMessage`; the matrix then resolves
the record's label through a small JSON API and appends a reference row.

## Flow

1. The matrix editor gains a **"New"** button beside the existing pick-existing
   controls. It creates a record of the type currently selected in
   `[data-matrix-type]`.
2. Clicking it opens a JS-built **overlay drawer** containing
   `<iframe src="{prefix}/{type}/new?_drawer=1">`.
3. The create form renders in a **bare drawer layout** — the same form fields,
   CSRF token, and multipart support as the normal create form, but with no
   sidebar / topbar / theme chrome.
4. On successful save, `store()` in drawer mode returns a tiny HTML page
   (`drawer_saved.twig`) that calls
   `window.parent.postMessage({source:'folio-drawer', type, id}, origin)` —
   instead of the usual `302` redirect to the list. Validation errors still
   re-render the **bare** form at `422`, inside the frame.
5. The parent's `admin.js` receives the message (origin- and shape-checked),
   closes the drawer, fetches `GET {prefix}/{type}/{id}/label` → `{id,label}`,
   and calls the existing `addRow(type, id, label)`.

The pick-existing flow (type-select + record-select + Add) is unchanged; the
embedded options blob still serves it. The label API is used **only** to resolve
the freshly-created id, which is not in that blob.

## Decisions (settled during brainstorming)

- **Drawer = JS-built overlay + `<iframe>`** (not a native `<dialog>`): full
  styling control, vanilla, no build step; matches the existing `admin.js`
  convention. One drawer at a time (modal).
- **postMessage carries `{source, type, id}` only — no label.** The matrix
  resolves the label via a JSON API. Cleaner than computing the label at save
  time, and the endpoint is reusable.
- **Label route is path-based** (`GET {prefix}/{type}/{id}/label`) to match the
  existing attribute-based routing, not a query-string `_api` endpoint.
- **Bare drawer layout** via a dedicated minimal layout; `form.twig` selects its
  parent layout through a passed `layout` variable so the field-rendering block
  stays single-sourced.

## Architecture

### Server / PHP

**`RecordLabeler` (new, `packages/folio/src/Content/RecordLabeler.php`).**
Extract the "first string field value, else id" logic currently private in
`MatrixFieldType::recordLabel()`/`refLabel()` into a tiny service:
`label(DynamicRecord $record): string`. Constructed with `TypeRegistry`. One
source of truth, used by **both** the matrix editor and the label API.
`MatrixFieldType` is refactored to delegate to it (gains a `RecordLabeler`
constructor dependency; its private label helpers call through). Behavior
identical — existing `MatrixFieldTypeTest` label assertions stay green.

**Label API (`AdminController::recordLabel()` + route).**
`GET {prefix}/{type}/{id}/label`:
- `404` (`Unknown type`) if `!catalog->has($type)`.
- `404` (`Not found`) if `dm->findType($type,$id)` is null.
- else `200 application/json` `{"id": "...", "label": "..."}`, label from
  `RecordLabeler`. The label is HTML-escaped at insertion time on the client
  (`addRow` already runs values through `esc()`), and the JSON response uses the
  default safe encoding. No CSRF needed (GET, read-only). Same admin-auth
  surface as every other folio route. New route added to `FolioRoutes::admin()`
  `$defs` (e.g. after the `editForm` entry):
  `['GET', $prefix . '/{type}/{id}/label', 'recordLabel']`.

**Drawer mode in `createForm` / `store`.**
Detect `?_drawer=1` from the query (`$request->getQueryParams()['_drawer'] ?? null`).
- `createForm` in drawer mode: render through the **bare layout** (pass a
  `layout` template var = `@folio/admin/_drawer_layout.twig`; normal mode keeps
  `@folio/admin/_layout.twig`). The create-form action must **preserve the
  `?_drawer=1` query** so the POST also runs in drawer mode
  (`action = {prefix}/{type}?_drawer=1`).
- `store` in drawer mode: on success, instead of `302`, return `200` HTML
  rendering `@folio/admin/drawer_saved.twig` with `type` + the new `id`. On
  `ValidationException`, re-render the **bare** form at `422` (action keeps
  `?_drawer=1`).
- The `form()` helper gains a `layout` parameter (default
  `@folio/admin/_layout.twig`) threaded into the template context; `createForm`
  and `store`'s error path pass the drawer layout when in drawer mode.

**`MatrixFieldType` gains `prefix`.** Inject `string $prefix` (from
`FolioServiceProvider::register`, where `$prefix` is already in scope) so the
options blob can carry it. `admin.js` builds the create + label URLs from it,
staying prefix-agnostic. The blob gains a top-level `"prefix"` key; per the
existing convention it is emitted with `JSON_HEX_TAG`.

### Templates

- **`@folio/admin/_drawer_layout.twig` (new).** Minimal layout: `<head>` with
  `admin.css` + the `editor_assets` loop (so a created type's own field JS/CSS —
  e.g. richtext/trix — still loads inside the frame), `<body class="folio-drawer-body">`
  with only `{% block content %}`. No sidebar, topbar, nav, or theme toggle.
- **`@folio/admin/form.twig` (modify).** Replace the hard-coded
  `{% extends "@folio/admin/_layout.twig" %}` with
  `{% extends layout|default('@folio/admin/_layout.twig') %}`. Everything else
  unchanged (the field loop, CSRF, multipart). In drawer mode the "Cancel" link
  is harmless (it would navigate the iframe to the list) — keep it for parity;
  the drawer's own close control is the primary exit.
- **`@folio/admin/drawer_saved.twig` (new).** A near-blank HTML document whose
  inline script posts the result to the opener and nothing else:
  ```html
  <!doctype html><meta charset="utf-8"><body>
  <script>
  (function () {
    var msg = { source: 'folio-drawer', type: {{ type|json_encode|raw }}, id: {{ id|json_encode|raw }} };
    if (window.parent && window.parent !== window) {
      window.parent.postMessage(msg, window.location.origin);
    }
  })();
  </script>
  </body>
  ```
  `type`/`id` are injected via `json_encode` (safe in a `<script>` context).
  Targets `window.location.origin` (parent and iframe are same-origin).

### `admin.js`

Additions (all within the existing IIFE; no new globals leaked):

- **Module-level state:** `activeMatrix` (the matrix root that opened the current
  drawer) and a reference to the current drawer overlay element.
- **`initMatrix` extension:** read `opts.prefix`; add a **"New"** button
  (`data-matrix-create`) to the add controls. On click, if `typeSel.value` is
  set, open the drawer for `prefix + '/' + typeSel.value + '/new?_drawer=1'`,
  recording `activeMatrix = root` and the chosen type.
- **`openDrawer(url)`:** build an overlay (`.folio-drawer`) containing a panel
  (`.folio-drawer-panel`), a close button (`.folio-drawer-close`), and an
  `<iframe>` with `src=url`; append to `document.body`. Close on the button,
  on overlay-backdrop click, and on `Escape`.
- **One global `message` listener** (added once at boot): ignore unless
  `event.origin === window.location.origin`, `event.data` is an object, and
  `event.data.source === 'folio-drawer'`. Then: close the drawer; if
  `activeMatrix` is set, `fetch(prefix + '/' + type + '/' + id + '/label')`,
  parse `{id,label}`, and call that matrix's `addRow(type, id, label)`
  (falling back to the id as label if the fetch fails). `addRow` must be
  reachable from the listener — refactor `initMatrix` so each matrix exposes its
  `addRow`/`prefix` (e.g. store a small per-root controller object, or attach
  `addRow` to the root element), keyed off `activeMatrix`.

### CSS (`admin.css`)

Add `.folio-drawer` (fixed full-viewport overlay + dimmed backdrop),
`.folio-drawer-panel` (right-anchored panel holding the iframe), `.folio-drawer-close`,
and `.folio-drawer iframe` (fills the panel) rules. Add a `data-matrix-create`
button style consistent with the existing `data-matrix-add`. Keep within the
established Folio admin visual language (the 5a/styling work).

## Security

- **postMessage origin pinning:** the saved page posts to
  `window.location.origin`; the parent listener accepts only
  `event.origin === window.location.origin` **and** `source==='folio-drawer'`.
  No wildcard target origin.
- **Label API:** read-only GET, admin-auth surface identical to existing folio
  admin routes; exposes only the same id+label already present in the editor's
  embedded picker blob. Guards unknown type/record with `404`.
- **Escaping:** label inserted client-side via the existing `esc()` in `addRow`;
  `drawer_saved.twig` injects `type`/`id` with `json_encode` inside `<script>`;
  the options blob keeps `JSON_HEX_TAG`. The created record itself is saved
  through the unchanged 5a `store()` path (full normalize/validation/CSRF).
- **CSRF:** the drawer create form carries the CSRF token via the unchanged
  `form_begin` (the bare layout reuses the same form body). The drawer POST is a
  normal same-origin form submit.

## New / affected files

- `packages/folio/src/Content/RecordLabeler.php` — **new**.
- `packages/folio/src/Field/Types/MatrixFieldType.php` — **modify** (inject
  `RecordLabeler` + `prefix`; delegate label logic; add `prefix` + per-type
  create affordance data to the options blob).
- `packages/folio/src/Http/AdminController.php` — **modify** (`recordLabel()`;
  `?_drawer=1` handling in `createForm`/`store`; `layout` param on `form()`).
- `packages/folio/src/Routing/FolioRoutes.php` — **modify** (label route).
- `packages/folio/src/FolioServiceProvider.php` — **modify** (pass `prefix` +
  `RecordLabeler` to `MatrixFieldType`; bind/construct `RecordLabeler`).
- `packages/folio/templates/admin/_drawer_layout.twig` — **new**.
- `packages/folio/templates/admin/drawer_saved.twig` — **new**.
- `packages/folio/templates/admin/form.twig` — **modify** (dynamic `extends`).
- `packages/folio/assets/admin.js` — **modify** (drawer + create + message
  listener + label fetch).
- `packages/folio/assets/admin.css` — **modify** (drawer + create-button styles).
- Tests (below).
- Demo: no new model needed — the existing `note` matrixable type + `page.blocks`
  matrix already exercises create-in-drawer end-to-end in `examples/folio-demo`.

## Testing

**Unit — `RecordLabelerTest` (new):** first-string-field value wins; falls back
to id when no string field / empty; unknown type → id.

**Unit — `AdminControllerTest` (or a focused new test):**
- `recordLabel` returns `200` JSON `{id,label}` for an existing record; `404`
  for unknown type and for missing id.
- `createForm?_drawer=1` renders the bare layout (no sidebar markup, e.g. absence
  of the `folio-sidebar` class / presence of a drawer-body marker) and a form
  action that preserves `?_drawer=1`.
- `store?_drawer=1` on success returns `200` containing the
  `folio-drawer` postMessage script with the new id (not a `302`); on validation
  failure returns `422` still in the bare layout.

**Unit — `MatrixFieldTypeTest` (extend):** options blob now includes `prefix`;
existing label assertions unchanged (delegation to `RecordLabeler` is behavior-
preserving).

**Integration — `FolioAppTest` (extend):** with the scaffolded `note` type,
`POST {prefix}/note?_drawer=1` (valid) responds `200` with the postMessage
payload carrying a non-empty id; `GET {prefix}/note/{id}/label` returns the
record's name as `label`. Confirms the round-trip the drawer relies on.

**Static (`admin.js`):** asserts the file defines the drawer open/close, the
`message` listener with the origin + `folio-drawer` guards, and the label fetch
(grep-level checks, matching how 5a statically checked `admin.js`).

**Full suite:** `vendor/bin/phpunit` green (only the pre-existing PHPUnit
deprecations + 1 skip).

## Out of scope

Per-placement view override → 5c. Editing an existing referenced record from
within the matrix. A search/paged picker endpoint (pick-existing stays embedded;
the label API resolves only freshly-created ids). Creating into a type that is
not in the field's effective-allowed set (the "New" button only lists
effective-allowed types, same set as pick-existing).
