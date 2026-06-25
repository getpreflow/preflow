# Folio Matrix 5c — Per-placement View Override — Design

**Date:** 2026-06-25
**Package:** `preflow/folio`
**Status:** Approved.
**Builds on:** `2026-06-19-folio-matrix-design.md` (5a core) and
`2026-06-22-folio-matrix-5b-create-in-drawer-design.md` (5b). This is the 5c
addendum that the 5a spec named: "store an optional `view` per reference and
resolve a per-type-per-view template, with a view selector in the editor.
Additive." No 5a/5b behavior changes.

## Goal

Each matrix reference may carry an optional `view`. When set, the referenced
record renders through a per-type-per-view template variant
(`@folio/frontend/types/{type}_{view}.twig`) instead of its default per-type
template — chosen **per placement** in the editor. A note referenced twice on a
page can render once as a `card` and once `inline`.

## Decisions (settled during brainstorming)

- **Template naming = flat `{type}_{view}.twig`** in the existing
  `@folio/frontend/types/` dir (e.g. `note_card.twig`), consistent with today's
  `note.twig` / `_default.twig`. Resolution order for a ref with `view`:
  `{type}_{view}.twig` → `{type}.twig` → `_default.twig`. A ref with no view
  keeps today's `{type}.twig` → `_default.twig`.
- **View discovery = declared per type in model JSON** (`"views": ["card",
  "inline"]`). The editor offers `Default` plus the declared list. Filesystem
  scanning was rejected (needs new FS code outside the engine's
  `exists()`/`render()` abstraction). The declared list is the source of truth
  for the editor selector and for input whitelisting; **render-time resolution
  is still just `exists()` + fallback**, so a template's presence — not the
  declaration — ultimately decides what renders.
- **Type-level views only.** Per-field narrowing (a matrix field exposing a
  subset of a type's views) is deferred (YAGNI). The type owns its view list.

## Data model

- Reference shape grows from `{_type, id}` to `{_type, id, view}`. **`view` is
  omitted when empty** (no override). Existing stored refs (no `view` key) are
  untouched — fully backward-compatible, and the 5a `toStorage`/`fromStorage`
  round-trip (`[{"_type":"note","id":"n1"}]`) stays byte-identical.
- **`TypeDefinition` gains `public array $views = []`** — constructor-promoted,
  appended last, mirroring exactly how 5a added `matrixable`. `TypeRegistry`
  populates it from the model JSON top-level `views` key (default `[]`, filtered
  to strings). **This is the only `preflow/data` change.**

## Architecture

### Frontend resolution — `RecordRenderer`

`renderTypeTemplate(DynamicRecord $record, string $view = '')` (new optional 2nd
param; existing callers unaffected):

```
$type = $record->getType()->key;
$candidates = [];
if ($view !== '' && preg_match('/^[a-z0-9_-]+$/', $view)) {
    $candidates[] = '@folio/frontend/types/' . $type . '_' . $view . '.twig';
}
$candidates[] = '@folio/frontend/types/' . $type . '.twig';
$candidates[] = '@folio/frontend/types/_default.twig';
// render the first that engine->exists(); _default always ships, so always resolves
```

Context passed gains `view` alongside the existing `record` / `rendered` /
`type`.

**Security:** `$view` is validated `^[a-z0-9_-]+$` before being interpolated
into a template name — defense-in-depth against path traversal
(`../`, slashes) even though the data layer already whitelists it. A
non-matching view is treated as no-view (falls through to the default chain).

### Editor + storage — `MatrixFieldType`

- **`toRefs`**: preserve a non-empty string `view` on each entry; omit it
  otherwise. (Unchanged for entries without a view.)
- **`normalizeInput`**: for each entry, in addition to the existing `_type`
  (must be in effective-allowed) and non-empty `id` checks, **whitelist the
  submitted `view` against the referenced type's declared `views`**
  (`$this->registry->get($_type)->views`). A view not in that list is dropped
  (entry stored without a `view`). This is the **primary security boundary** —
  exactly parallel to how `_type` is filtered against `allowed` — so stored
  views are always trusted simple strings.
- **`renderEditor`**:
  - The options blob gains `views: { {type}: [..declared views..], … }` built
    from `$this->registry->get($key)->views` for each effective-allowed type.
  - Each row renders a `<select name="{field}[{i}][view]">` with a `Default`
    option (empty value) plus the type's declared views, the current `view`
    pre-selected — **rendered only for types that declare ≥1 view** (a type with
    no views gets no selector and no `view` input). The `<select>` is itself the
    submitted input (no separate hidden field).
- **`renderFrontend`**: pass `$ref['view'] ?? ''` to
  `renderTypeTemplate($record, $view)`.

The per-row view `<select>` markup produced by `rowHtml` must stay
byte-identical to the `admin.js` `addRow` version (the 5b client/server
markup-parity invariant).

### `admin.js`

`addRow(type, id, label, view)` builds the same per-row view `<select>` from
`opts.views[type]` (omitted when the type declares no views), pre-selecting
`view` when provided. The drawer create-flow (5b) appends new rows with no view
(`Default`). Everything else in `admin.js` is unchanged.

## New / affected files

- `packages/data/src/TypeDefinition.php` + `TypeRegistry.php` — add `views`.
- `packages/folio/src/Content/RecordRenderer.php` — `renderTypeTemplate` gains
  `$view`, the candidate chain, the view-char guard, and `view` in context.
- `packages/folio/src/Field/Types/MatrixFieldType.php` — `toRefs`/`normalizeInput`
  carry+whitelist `view`; `renderEditor` emits the per-row select + `views` in
  the options blob; `renderFrontend` passes the view through.
- `packages/folio/assets/admin.js` — `addRow` builds the view select.
- `packages/folio/assets/admin.css` — minor style for the per-row view select
  (consistent with existing `folio-matrix-controls`).
- Demo: `examples/folio-demo/config/models/note.json` (add `"views": ["card"]`),
  `examples/folio-demo/resources/folio/frontend/types/note_card.twig` (new view
  variant), and the demo page composing a note block that uses it.
- Tests (below).

## Testing

**Unit — data layer:** `TypeRegistry` reads `views` (defaults `[]`, filters to
strings); `TypeDefinition` exposes it. (Parallels the 5a `matrixable` test.)

**Unit — `RecordRenderer`:** with a `view`, resolves `{type}_{view}.twig` when it
exists; falls back to `{type}.twig`, then `_default.twig`; with empty view, today's
behavior; a traversal-ish view (`../x`, `a/b`) is rejected and falls back;
`view` is present in the render context.

**Unit — `MatrixFieldType`:** `toRefs`/`fromStorage` round-trip a `{_type,id,view}`
ref and a viewless ref; `normalizeInput` keeps a declared view, drops an
undeclared view (stores viewless), and still drops disallowed `_type`/empty `id`;
`renderEditor` emits the per-row `[view]` select with `Default` + declared
options for a type that declares views, and **no** select for a type that
declares none; the options blob includes the `views` map; `renderFrontend` routes
a ref's view into the resolved template.

**Static — `admin.js`:** `addRow` references `opts.views` and builds a
`[{i}][view]` select.

**Integration — `FolioAppTest` (real Twig):** a page block whose ref has
`view=card` renders the `note_card.twig` variant; a viewless ref renders the
default `note.twig`; a stored ref round-trips through the editor (the select
shows the saved view); a pre-5c stored ref (`{_type,id}` with no view) still
renders (backward-compat).

**Full suite:** `vendor/bin/phpunit` green (only the pre-existing PHPUnit
deprecations + 1 skip); folio + demo green under strict Twig.

## Out of scope

Per-field view narrowing (type-level only here). Filesystem auto-discovery of
views. A global/non-matrix use of per-view rendering (this is a matrix
placement feature; the `RecordRenderer` change is backward-compatible and could
be reused later, but no other caller is added). Renaming/migrating existing
per-type templates.
