# Preflow CMS — Walking Skeleton Design

**Date:** 2026-06-18
**Status:** Proposed
**Package:** `preflow/cms` (new)

## Context

Crelish (`giantbits/yii2-crelish`, v0.21.6) is a mature Yii2-based CMS we intend to
retire in favour of a new CMS built natively on Preflow. Crelish's defining strengths,
which the successor must preserve:

- **Workspace drop-in extensibility** — drop a folder/definition into `workspace/` and a
  new admin area appears with zero core edits. Full feature flexibility without touching
  the framework. *This is the crown jewel.*
- **JSON-defined content types** — fields, validation, and form layout declared in JSON,
  driving auto-generated admin forms.
- **Fast and easy** — you could do anything with it quickly.

Crelish's weaknesses the successor should shed: an enormous, fragile dependency surface;
kitchen-sink coupling (CMS tangled with analytics, newsletter, PDF, bot-detection);
dual-file friction (JSON definition + hand-synced PHP ActiveRecord model); implicit
magic (lowercase `$ctype` discovery, ucfirst fallbacks). The one feature that *failed* in
Crelish was **live preview** — an iframe reloaded in full on every edit, unable to render
unsaved drafts.

The north star is **Craft CMS** (server-rendered, JS only where genuinely needed), not
Gutenberg. The successor is **lean and embeddable** (core driver), with **content
modeling as the backbone** that drives the editor experience.

### Why this is achievable Preflow-native (no SPA framework)

Preflow already provides a Hotwire/Livewire-class stack:

- `preflow/components` — PHP class + co-located Twig, lifecycle (`resolveState`),
  actions, fragment rendering, `ErrorBoundary`.
- `preflow/htmx` — signed action tokens, 5-layer security gate, `hx-*` generation,
  server-sent events (`HX-Trigger`, `hd.on`), swappable `HypermediaDriver`.
- `preflow/data` — **already ships the CMS modeling primitive** (see below).
- `preflow/form` — auto-form from validation rules, model binding, inline validation via
  the hypermedia driver.
- `preflow/routing` — file-based (`app/pages/`) + attribute routes, with matching
  priority **static > dynamic > catch-all**.

The interactivity a CMS needs ("feels like an app") comes from no-reload fragment swaps +
inline validation + autosave — exactly what HTMX + components deliver. A client framework
is reserved for a small, well-known set of JS "islands" (rich text, drag-reorder, a few
inputs), introduced in later specs — **not** in this skeleton.

### The modeling backbone already exists

`preflow/data` already implements JSON-defined dynamic content types end to end:

- `TypeRegistry` loads a `TypeDefinition` from `{models_path}/{type}.json`.
- `TypeDefinition` holds key, table, storage, fields, idField, searchable fields,
  transformers, and exposes `validationRules()`.
- `TypeFieldDefinition` — per-field type, searchable, transform, validation rules.
- `DynamicRecord` — runtime record bound to a `TypeDefinition`, with storage transformers.
- `DataManager::findType / queryType / saveType / deleteType` — full CRUD with
  validation (`validateDynamicRecord`), across JSON-file **and** SQLite/MySQL storage,
  per-type.
- `QueryBuilder::forType()` — filter/search/paginate returning `DynamicRecord`s.

Subsystem "content modeling + storage" is therefore **done**. This skeleton is almost
entirely the CMS layer *on top*.

## Goals

Prove the full architecture with one thin vertical slice:

> Drop `workspace/types/page.json` into an app → a **Pages** admin area appears
> automatically → list / create / edit / delete a page through an auto-generated form →
> the page renders on the public frontend at its slug URL.

This exercises every seam: workspace discovery → type definition → storage CRUD →
auto-form → routing precedence → frontend render.

## Non-Goals (explicitly out of this slice)

Each is its own later spec:

- Rich text / WYSIWYG, relation fields, asset/media fields, matrix/block content
- **Live preview** (draft-aware, surgical, iframe) — designed conceptually, built later
- i18n / translations, versioning / drafts, search UI
- Real authentication / authorization (stubbed admin gate here)
- Custom/bespoke admin areas (arbitrary controllers per workspace folder) — skeleton
  proves the discovery *mechanism* with the generic data-driven case only
- Asset pipeline, image transforms, analytics, newsletter, PDF — never in core

## Architecture

A new thin package, **`preflow/cms`**, composes existing packages and owns only what is
genuinely new. It does not reinvent data, forms, routing, or components.

| Concern | Owner | New? |
|---|---|---|
| Type definition (`page.json`) | `data` `TypeRegistry` / `TypeDefinition` | existing |
| Record CRUD + validation | `data` `DataManager::*Type()` | existing |
| Auto-form from type | `form` builder (reads validation rules) | existing + thin glue |
| **Admin area discovery** | `cms` `WorkspaceScanner` | **new — crown jewel** |
| Admin shell (nav, list, edit) | `cms` admin components | **new** |
| Frontend URL → record → render | `routing` catch-all + `cms` resolver | existing + thin glue |
| Auth gate | `auth` (stubbed to "is admin") | existing, deferred |

### The crown jewel: workspace discovery

`WorkspaceScanner` scans `workspace/types/*.json` and builds a registry of available
content types. The admin shell renders **one nav entry + one generic CRUD area per
discovered type** — no registration code, no core edits. Drop a JSON file, get an admin
area.

Malformed JSON files are skipped (logged), never fatal to the admin.

### Routing precedence model (the Crelish interception, done cleanly)

Crelish intercepted Yii2's request flow to do slug-based routing. Preflow needs no hack:
its router already matches **static > dynamic > catch-all**. The CMS registers its
frontend resolver as the **lowest-priority catch-all**.

- **Without `preflow/cms`:** file-based `app/pages/` + attribute controllers — a normal
  Preflow app.
- **With `preflow/cms`:** those still take precedence. Unmatched requests fall through to
  the CMS slug resolver, which looks up content by slug. The **workspace folder becomes
  the primary place to work.**

Admin routes (`/admin/*`) are registered by the CMS as ordinary high-priority routes,
namespaced so they never clash with app routes.

### Conventions (skeleton)

| Thing | Location / value |
|---|---|
| Admin mount path | `/admin` (configurable, this is the default) |
| Type definitions | `workspace/types/*.json` (`data` `models_path` points here) |
| Frontend template per type | `workspace/views/{type}.twig` |
| Demo type | `page` |

`workspace/` is "where you work" when the CMS is active. The skeleton uses
`workspace/types/` and `workspace/views/`; later specs may add `workspace/models/`
(custom PHP logic) and custom admin folders.

## Data Flow

**Admin — edit cycle:**

1. `GET /admin` → `WorkspaceScanner` lists types → nav with one entry per type.
2. `GET /admin/page` → `DataManager::queryType('page')` → list view (table of records).
3. `GET /admin/page/{id}/edit` (or `/new`) → `findType` → `form` renders fields from the
   `TypeDefinition`'s validation rules.
4. Submit → CMS admin component action → `validateDynamicRecord` → `saveType`. On error,
   the form re-renders with the `ErrorBag` (inline validation already works via the
   hypermedia driver); on success, HTMX swaps the result / redirects.

**Frontend — render cycle:**

1. Request not matched by `app/pages/` or controllers → CMS catch-all resolver.
2. Resolver looks up a `page` whose `slug` matches the path (only `published` records).
3. Hit → render `workspace/views/page.twig` with the `DynamicRecord`'s fields → 200.
4. Miss → 404.

## Demo Content Type

`workspace/types/page.json`:

| Field | Type | Notes |
|---|---|---|
| `title` | string | required |
| `slug` | string | required, unique-ish (skeleton: simple match) |
| `body` | text | textarea in the form |
| `status` | string | select: `draft` / `published` |

**Storage:** JSON-file for the demo type — zero-config, no migration, the strongest
"drop in and go" proof. The identical `DataManager` API swaps to SQLite by changing the
type's `storage` value, so nothing in the CMS layer is storage-specific.

## Error Handling

- Admin errors surface through the components `ErrorBoundary` (dev panel / prod fallback).
- Malformed type JSON → skipped by the scanner, logged, non-fatal.
- Frontend slug miss → 404.
- Validation failures → re-render form with `ErrorBag`, no save.

## Testing

Following the `testing` package and TDD:

- `WorkspaceScanner`: discovers valid types, ignores and logs malformed JSON, empty workspace
  yields empty registry.
- Admin CRUD round-trip: create → list → edit → save → delete against a temp workspace +
  JSON-file storage; assert validation rejects an invalid record.
- Frontend resolver: published slug → renders template with field values; unknown slug →
  404; `draft` status → not resolvable.
- Precedence: an `app/pages/` route and a controller route both win over a CMS slug of the
  same path.

## Future Specs (decomposition, build order)

1. **Field / editor system** — rich text (TipTap island), relation picker, asset picker,
   matrix/blocks; the JS-island pattern.
2. **Live preview** — draft-aware (render in-progress form state through the template
   path, no save) + surgical (postMessage debounced fragment swap into a resizable iframe
   for true responsive preview). Fixes both Crelish failures; keeps the iframe for style
   isolation + viewport simulation.
3. **Custom admin areas** — arbitrary controllers/views per workspace folder.
4. **Assets / media** — upload, image transforms, library.
5. **Add-ons** — i18n, versioning/drafts, search. Always optional, never in core.
