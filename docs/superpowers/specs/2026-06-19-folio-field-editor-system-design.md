# Folio Field/Editor System — Design

**Date:** 2026-06-19
**Package:** `preflow/folio` (with a small addition to `preflow/data`)
**Status:** Approved, ready for implementation plan(s)
**Roadmap:** CMS item #2 (field/editor system). Follows the admin styling work
(`2026-06-19-folio-admin-styling-design.md`).

## Goal

Turn Folio's minimal, scalar-only field handling into an extensible **field-type
framework**, and ship four real editors on top of it: rich text, asset upload,
relation picker, and matrix/repeatable blocks. One cohesive design, delivered in
sequenced build phases so each ships and is testable on its own.

## Background (current state, from a full pipeline trace)

- A content type is a `TypeDefinition` of `TypeFieldDefinition`s
  (`name`, `type`, `searchable`, `transform`, `validate`). Field types are mapped
  to HTML inputs by a hardcoded 3-case `FieldMapper::inputFor()`.
- `preflow/form` renders inputs; the admin `form.twig` calls
  `form.field(name, {type, value, errors})` — it only receives `{name, input}`
  per field, **not** the full field definition (no label/help/options/config).
- Storage: `DynamicRecord::toArray()/fromArray()` + per-field `FieldTransformer`
  (`toStorage`/`fromStorage`) already exist; `JsonFileDriver` JSON-encodes the
  whole record. So **structured (non-scalar) values are storable today** via a
  transformer that serializes to a JSON string.
- Validation: `DataManager::saveType()` validates and throws
  `ValidationException`. **`AdminController` never catches it** → a validation
  failure currently 500s, and the form's `errors` slot is never populated. This
  is a live bug.
- Frontend: `templates/frontend/page.twig` renders `{{ record.body|raw }}`
  **unescaped** with **no sanitization** → XSS risk for any HTML field.
- The Folio admin renders in **action mode**, which bypasses `AssetCollector`
  injection. The styling work established the pattern: serve admin assets from a
  **package-owned route** (`{prefix}/_assets/admin.css`) and put `<link>`/
  `<script>` as plain markup in `_layout.twig`.
- **No relation support** exists in `preflow/data` (confirmed); relations are a
  separate roadmap item.

## Decisions (locked)

| Topic | Decision |
|-------|----------|
| Architecture | A `FieldType` interface + `FieldTypeRegistry`; built-ins registered, userland-extensible. Replaces `FieldMapper`. |
| Rich text | Vendor **Trix** (prebuilt `trix.js` + `trix.css`, pinned, self-hosted, no build, no external request). |
| Rich-text safety | Sanitize HTML on save with **`symfony/html-sanitizer`** (pure-PHP allowlist). |
| Asset field | File upload → `storage/uploads/`, store path(s); served via package route `{prefix}/_uploads/{path}`. |
| Relation field | Minimal: config `{to, multiple}`; store target IDs as JSON; server-rendered picker; resolver for templates. No data-layer relation model. |
| Matrix field | Named block types with their own fields (registry used recursively); stored as a JSON array of `{_type, fields}`. **Requires JS** for add/remove/reorder (accepted — the use case needs it). |
| Asset delivery | Generalize the package asset route to serve `admin.js` + vendored editor bundles; editors are progressive enhancement on server-rendered mount points. |
| Storage portability | `toStorage` returns a JSON string for structured fields, so values persist across JSON-file and SQL drivers alike. |

## Architecture

### FieldType interface

A field type is a self-contained unit. Proposed namespace `Preflow\Folio\Field`.

```php
interface FieldType
{
    /** Stable key used in model JSON ("type": "...") and the registry. */
    public function key(): string;

    /** Admin-form HTML for this field (mount point for progressive enhancement). */
    public function renderEditor(FieldContext $ctx): string;

    /** Raw POST value -> domain value (includes sanitization for richtext). */
    public function normalizeInput(mixed $raw, array $config): mixed;

    /** Domain value -> storage-ready value (JSON string for structured types). */
    public function toStorage(mixed $value): mixed;

    /** Storage value -> domain value. */
    public function fromStorage(mixed $value): mixed;

    /** Safe HTML for the public frontend. */
    public function renderFrontend(mixed $value, array $config): string;

    /** Validation rules contributed by this field, given its config. */
    public function rules(array $config): array;

    /** Asset handles (css/js) this editor needs in the admin (keys into the asset route). */
    public function assets(): array;
}
```

`FieldContext` carries what an editor needs to render: field name, label, help,
current value, errors, and the per-field `config` bag.

### FieldTypeRegistry

- Maps `type` key → `FieldType` instance.
- Built-ins registered at boot: `string`, `text`, `number`, `richtext`,
  `asset`, `relation`, `matrix`.
- Userland can register/override a field type (extension DX consistent with
  Folio's override model). Unknown types fall back to a plain text field.
- Replaces `FieldMapper` (which is removed; `string/text/number` become trivial
  `FieldType`s).

### Data-layer addition (`preflow/data`)

Extend `TypeFieldDefinition` with:
- `label: ?string` — human label (falls back to a humanized name).
- `help: ?string` — help text.
- `config: array` — per-type configuration bag (e.g. `relation`, `asset`,
  `matrix`, `richtext` keys), populated from the field's JSON entry.

`TypeRegistry` reads these from the model JSON. No other data-layer change; in
particular **no relation model** is added.

### Save / load flow (Folio owns (de)serialization via the registry)

- **Save** (`AdminController::store/update`): for each field, the registry's
  `normalizeInput()` (sanitize/parse) then `toStorage()` produce the value
  written via `DataManager::saveType()`. Validation runs as today; on
  `ValidationException`, re-render the form with submitted values + per-field
  errors (HTTP 422).
- **Read** (admin edit + `FrontendController`): `fromStorage()` restores domain
  values; `renderEditor()` (admin) or `renderFrontend()` (public) produce HTML.

The data layer stays generic — it stores whatever scalar/JSON-string it is
given. (We do **not** push Folio field types into `preflow/data`.)

### Admin asset delivery

Generalize the existing package-owned asset controller to serve an **allowlisted**
set of files from `packages/folio/assets/` (and `assets/vendor/`):
`admin.css` (existing), `admin.js`, `vendor/trix.js`, `vendor/trix.css`, etc.
Each is content-hash versioned (same `?v=` seam as the stylesheet) and sent with
long-lived cache headers. The route remains `{prefix}/_assets/{file}` with a
strict filename allowlist (no path traversal).

`_layout.twig` always includes `admin.js`. Field types declare needed assets via
`assets()`; the admin controller unions them per page and the layout emits the
extra `<link>`/`<script>` tags (plain markup — action mode bypasses the
collector). `admin.js` is a tiny vanilla bootstrap that scans for
`data-folio-editor` / block mount points and upgrades them.

## Field types

### string / text / number (Phase 1)
Trivial `FieldType`s wrapping the existing `preflow/form` inputs (`text`,
`textarea`, `number`). Prove the framework end-to-end with no behavior change for
existing models.

### richtext (Phase 2)
- **Editor:** server renders a hidden `<input>` + a `<trix-editor>` mount;
  `admin.js` (with vendored `trix.js`) binds them. `trix.css` styles it, themed
  to match the admin tokens where practical.
- **Input:** `normalizeInput()` runs the submitted HTML through
  `symfony/html-sanitizer` with a Folio allowlist (headings, lists, links,
  emphasis, blockquote, code, images by URL — no scripts/styles/event attrs).
- **Storage:** sanitized HTML string (scalar).
- **Frontend:** sanitized HTML rendered `|raw` (safe because sanitized on save).
- **Assets:** `vendor/trix.js`, `vendor/trix.css`.

### asset (Phase 3)
- **Config:** `{ "multiple": bool, "accept": "image/*" }`.
- **Editor:** file input + inline list/preview of current path(s) with remove.
- **Input:** handle PSR-7 uploaded files (`getUploadedFiles()`); validate
  extension/MIME against `accept`; store under `storage/uploads/<yyyy>/<mm>/` with
  a randomized filename; keep existing paths not removed. Value = path string
  (single) or JSON array of paths (multiple).
- **Serving:** package route `{prefix}/_uploads/{path}` streams files from
  `storage/uploads/` with the correct content-type (path-traversal guarded). This
  keeps uploads working drop-in (no public symlink); production / media library
  (#4) can later front this with the web server.
- **Frontend:** `<img>`/link to the upload URL.

### relation (Phase 4, minimal)
- **Config:** `{ "to": "<typeKey>", "multiple": bool, "labelField": "<field>"? }`.
- **Editor:** server-rendered `<select>` (single) or multi-select / checkbox list
  (multiple), options = target type's records. The option label uses
  `config.labelField` if set, else the target's first string field (commonly
  `title`), else the record id. Works with no JS; `admin.js` may add
  search-as-you-type later.
- **Storage:** target ID (single) or JSON array of IDs (multiple).
- **Resolver:** a helper resolves stored IDs → records for admin display and
  frontend templates (queried on demand; N+1 acceptable at this scale, noted for
  the future relation model).
- **Frontend:** `renderFrontend()` exposes resolved record(s) (default render:
  linked titles; templates can override).

### matrix / repeatable blocks (Phase 5)
- **Config:** named block types, each with its own fields:
  `{ "blocks": { "hero": { "label": "...", "fields": { ... } }, "text": { ... } } }`.
  Block fields reuse the **registry recursively**.
- **Storage:** JSON array `[{ "_type": "hero", "fields": { ... } }, ...]` (order
  preserved).
- **Editor:** lists block instances; each block's fields render via the registry.
  **Requires JS** (`admin.js`) for add (choose block type) / remove / reorder
  (drag or up/down); on submit the ordered blocks serialize into the form.
  Without JS, existing blocks remain editable but structure can't change
  (graceful degradation).
- **Frontend:** iterate blocks; each renders via its fields' `renderFrontend()`
  (or an overridable block template).

## Security

- **Rich text:** allowlist-sanitized on save; no script/style/event-handler
  attributes survive. Frontend `|raw` is safe because storage is clean.
- **Uploads:** validate against the field's `accept`; randomized stored
  filenames; served with explicit content-type via the route; path-traversal
  guarded on both the `_uploads` and `_assets` routes (strict allowlist /
  realpath check).
- **Everything else** stays Twig-autoescaped; only sanitized rich text and
  intentional block output use `|raw`.
- **CSRF:** all admin POSTs already carry `_csrf_token`; uploads use the same
  form, so they're covered.

## New dependencies

- **`symfony/html-sanitizer`** — composer, pure PHP, allowlist HTML sanitizer.
  Added to `packages/folio`.
- **Vendored Trix** — `trix.js` + `trix.css` committed under
  `packages/folio/assets/vendor/` at a pinned version. A prebuilt third-party
  bundle we serve ourselves (not composer/npm). Record the version + source URL
  in a `VENDOR.md` next to it for provenance/upgrades.

## Build phases (one spec, staged delivery)

1. **Framework + foundation** — `FieldType`/`FieldContext`/`FieldTypeRegistry`;
   extend `TypeFieldDefinition` (`label`/`help`/`config`); pass full field defs to
   the template; validation-error round-trip (422 + repopulated form);
   generalize the asset route; `admin.js` bootstrap. Port `string/text/number`
   to field types and remove `FieldMapper`. No behavior change for existing
   models beyond now-visible validation errors.
2. **Rich text** — vendor Trix; `symfony/html-sanitizer`; richtext field type;
   sanitized frontend render.
3. **Asset** — upload handling, `storage/uploads/`, `{prefix}/_uploads` route,
   asset field type.
4. **Relation** — minimal ID-list relation field type + resolver.
5. **Matrix** — recursive block field type + `admin.js` block editor.

Each phase gets its own implementation plan (or a clearly phased single plan) and
its own subagent-driven build + review cycle.

## Testing

Per field type (unit): `renderEditor` emits the expected mount/markup;
`normalizeInput` parses/sanitizes (richtext: scripts stripped; asset: bad MIME
rejected); `toStorage`/`fromStorage` round-trip; `renderFrontend` output is safe.
Registry: resolution, fallback for unknown type, userland override.
Integration (real kernel, strict Twig): a demo model exercising every field type
round-trips create → edit → frontend; validation failure re-renders with errors
(422); upload stores + serves; relation picker lists + resolves; matrix blocks
persist and re-render in order. Asset/uploads routes: content-type + traversal
guard. Keep the `examples/folio-demo` model(s) updated to showcase each type.

## Affected / new files (anticipated)

- `packages/data/src/TypeFieldDefinition.php` — add `label`, `help`, `config`.
- `packages/data/src/TypeRegistry.php` — read the new keys.
- `packages/folio/src/Field/FieldType.php`, `FieldContext.php`,
  `FieldTypeRegistry.php` — **new** framework.
- `packages/folio/src/Field/Types/{String,Text,Number,RichText,Asset,Relation,Matrix}FieldType.php`
  — **new** field types.
- `packages/folio/src/Content/FieldMapper.php` — **removed** (replaced).
- `packages/folio/src/Http/AdminController.php` — registry-driven render/save,
  validation round-trip, upload handling.
- `packages/folio/src/Http/AssetController.php` — generalized allowlist serving
  (`admin.js`, vendored editor bundles).
- `packages/folio/src/Http/UploadController.php` — **new**, serves `_uploads`.
- `packages/folio/src/Routing/FolioRoutes.php` — `_assets/{file}` (generalized),
  `_uploads/{path}`.
- `packages/folio/assets/admin.js` — **new** editor bootstrap.
- `packages/folio/assets/vendor/trix.js`, `trix.css`, `VENDOR.md` — **new**.
- `packages/folio/templates/admin/form.twig` — registry-driven field rendering +
  per-page editor assets.
- `packages/folio/templates/frontend/page.twig` — registry-driven frontend
  render.
- `packages/folio/composer.json` — add `symfony/html-sanitizer`.
- Tests under `packages/folio/tests/` and `examples/folio-demo` model updates.

## Out of scope (YAGNI / later roadmap)

Full relation model (belongsTo/hasMany, eager loading, integrity) — relations
roadmap item; central media library/browser — #4; live preview — #3;
collaborative editing; field-level permissions; i18n of field *content*.
