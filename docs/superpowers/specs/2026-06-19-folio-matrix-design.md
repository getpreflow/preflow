# Folio Matrix / Repeatable Blocks — Design

**Date:** 2026-06-19
**Package:** `preflow/folio`
**Status:** Shipped — 5a, 5b, and 5c all merged to `main`. 5b/5c have their own
addendum specs (see Decomposition below).
**Supersedes:** the "matrix / repeatable blocks" section of
`2026-06-19-folio-field-editor-system-design.md` — that section assumed
inline-defined blocks; the real model (confirmed with the user) is
**polymorphic references to standalone records**, below.

## Goal

A `matrix` field = an **ordered, polymorphic reference list**. On a content
type (typically a Page), the author composes the page by adding references to
existing records of *allowed* content types, orders them, and the public
frontend renders each referenced record via a per-type template. It is the
Phase-4 relation field generalized: many types + ordered + per-type rendering.

## The real model (corrected from the original field/editor spec)

Matrix entries are **references to standalone records** — NOT inline-defined
blocks. Adding (say) a news item to a Page's matrix links an existing `news`
record; the news record lives in its own storage/list, the matrix just holds an
ordered list of `{_type, id}` references. This makes matrix a sibling of the
relation field, reusing the field-type framework and the per-record frontend
rendering already built in Phases 1–4.

## Decomposition (one feature, three cycles)

- **5a — Matrix core (THIS spec) — SHIPPED:** ordered polymorphic references;
  per-type `matrixable` opt-in + per-matrix `allowed` list; **add by picking
  existing** records; reorder/remove via `admin.js`; per-type frontend templates.
  Merged in `9777e73` (Merge branch `feat/folio-matrix-5a`).
- **5b — Create-in-drawer — SHIPPED:** an "add new" flow that opens the target
  type's create view in a drawer/iframe and, on save, passes the new record's
  id back to the matrix via `postMessage` (the create view gains a
  "return to opener" mode); the matrix resolves the new record's label via a
  `GET {prefix}/{type}/{id}/label` API. Additive on top of 5a. Spec:
  `2026-06-22-folio-matrix-5b-create-in-drawer-design.md`; merged in `b5c9374`.
- **5c — Per-placement view override — SHIPPED:** store an optional `view` per
  reference and resolve `@folio/frontend/types/{type}_{view}.twig` (falling back
  to `{type}.twig` → `_default.twig`), with a view selector in the editor
  populated from the type's model-declared `views`. Additive. Spec:
  `2026-06-25-folio-matrix-5c-per-placement-view-design.md`; merged in `fd52e69`.

Each cycle got its own spec addendum → plan → subagent-driven build. Deferred
(no cycle planned): per-field view narrowing and filesystem view auto-discovery
(see the 5c spec's "Out of scope").

## 5a architecture

### Storage
The field stores a JSON array of references, order preserved:
```json
[{ "_type": "news", "id": "abc" }, { "_type": "gallery", "id": "xyz" }]
```
`MatrixFieldType::toStorage`/`fromStorage` = JSON ↔ array (same convention as
the asset and relation field types).

### Allowed-types config (two layers)
- **Per-type opt-in:** a content type's model JSON declares `"matrixable": true`.
  Only matrixable types may appear in any matrix. New optional key on the type
  definition; defaults to `false`. (Small `preflow/data` `TypeDefinition`
  addition — the only data-layer change.)
- **Per-matrix allow-list:** the field config
  `{ "type": "matrix", "matrix": { "allowed": ["news", "gallery"] } }`.
  **Effective allowed = `allowed ∩ matrixable`**; if `allowed` is absent/empty →
  **all** matrixable types.

### Editor + `admin.js`
This phase introduces Folio's first admin JS (`packages/folio/assets/admin.js`,
served via the existing asset route, declared by `MatrixFieldType::assets()` so
it loads only on pages that have a matrix field).

`MatrixFieldType::renderEditor` outputs:
- a container `<div class="folio-matrix" data-folio-matrix data-field="{name}">`;
- the existing reference rows, each showing the type label + the resolved record
  label, with reorder (up/down) and remove controls, plus hidden inputs
  `{name}[{i}][_type]` and `{name}[{i}][id]`;
- an **add** control listing the effective-allowed types;
- an embedded JSON blob of pickable records (allowed type → list of
  `{id, label}`) so the add picker needs no new AJAX endpoint for the MVP.

`admin.js`, scoped to each `[data-folio-matrix]`, handles: add (pick a type then
a record → append a row), remove, and reorder (move the row in the DOM). On
submit, rows serialize in DOM order; `normalizeInput` reindexes via
`array_values`, so DOM order is the stored order regardless of bracket indices.

`MatrixFieldType::normalizeInput(raw, config)` reconstructs the ordered
`[{_type, id}]` list from the submitted nested array, **dropping entries whose
`_type` is not in the effective-allowed set** or whose `id` is empty.

### Frontend rendering (per-type templates, userland-authored)
On render, for each reference the matrix loads the record (`DataManager::findType`)
and renders it via a **per-type template** resolved as
`@folio/frontend/types/{type}.twig`. Because `FolioServiceProvider` registers the
userland `resources/folio/` dir *first* in the `@folio` namespace, authors define
these templates in **userland** (`resources/folio/frontend/types/news.twig`); the
package ships only an overridable default `@folio/frontend/types/_default.twig`.
Resolution: if `engine->exists('@folio/frontend/types/{type}.twig')` render it,
else render the default. Each per-type template receives that record's `record`
(raw values) + `rendered` (the registry `renderFrontend` map) — the same shape
`page.twig` already uses. The matrix's own `renderFrontend` concatenates the
per-reference output.

### Recursion / reuse
`MatrixFieldType` is constructed with the `FieldTypeRegistry` and `DataManager`.
Because it is registered LAST in the registry, the registry reference is fully
populated by use-time. It resolves each referenced record's fields through the
registry (`fromStorage` + `renderFrontend`) to build the `rendered` map handed
to the per-type template — reusing every field type from Phases 1–4.

### Security
All labels, type keys, ids, and resolved values are HTML-escaped in the editor
and in the default frontend template. Per-type frontend templates are normal
Twig (autoescaped); rich-text fields render through their already-sanitized
`renderFrontend`. The matrix guards unknown/non-matrixable types (no crash:
unknown `_type` references are skipped on render, mirroring the relation field's
`registry->has()` guard). The embedded picker blob contains only ids + labels of
matrixable records the admin can already see.

## New / affected files (anticipated, 5a)

- `packages/data/src/TypeDefinition.php` + `TypeRegistry.php` — add `matrixable`.
- `packages/folio/src/Field/Types/MatrixFieldType.php` — **new**.
- `packages/folio/assets/admin.js` — **new** (matrix add/remove/reorder).
- `packages/folio/src/FolioServiceProvider.php` — register `MatrixFieldType`
  (with registry + DataManager) + add `admin.js` to the asset allowlist/map.
- `packages/folio/templates/frontend/types/_default.twig` — **new** fallback.
- `packages/folio/src/Http/FrontendController.php` or a small renderer helper —
  per-type template resolution for matrix references (may reuse the existing
  registry-driven render path).
- Tests under `packages/folio/tests/`; demo: a `matrixable` type + a Page matrix
  field in `examples/folio-demo`.

## Testing (5a)

Unit (`MatrixFieldType`): `normalizeInput` reconstructs ordered refs and drops
disallowed/unknown types; `toStorage`/`fromStorage` round-trip; effective-allowed
= matrixable ∩ allowed (and = all matrixable when allowed absent);
`renderEditor` emits rows, the `data-folio-matrix` hook, the embedded options
blob, and reorder/remove controls; `renderFrontend` resolves references and uses
the per-type template (and the `_default` fallback when none exists), skipping
unknown ids. Integration (real kernel, strict Twig): a Page with a matrix lists
the picker over a matrixable type, a reference round-trips (edit shows it), and
the frontend renders the referenced record via its per-type template. Static
check that `admin.js` defines the matrix add/remove/reorder behaviors.

## Out of scope (5a)

Create-in-drawer (iframe/postMessage) → 5b. Per-placement view override → 5c.
Inline editing of a referenced record from within the matrix. Search/paged
picker endpoint (records embedded for now; fine until datasets grow). No nested
multipart — matrix references whole records, so upload handling is unaffected.
