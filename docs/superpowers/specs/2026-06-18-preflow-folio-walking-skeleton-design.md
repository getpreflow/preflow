# Folio — Walking Skeleton Design

**Date:** 2026-06-18
**Status:** Proposed
**Package:** `preflow/folio` (new)
**Mounts at:** `/folio` (configurable)

## Context

Folio is a new CMS, built natively on Preflow, to **retire** the Yii2-based Crelish
(`giantbits/yii2-crelish`, v0.21.6). The name (a leaf of a work; a curated collection of
pages) is editorial and content-native; it does not need to contain "Preflow," just as
Crelish did not contain "Yii2."

### What Folio is (the corrected mental model)

Folio is a **drop-in CMS package**, not a discovery helper. `composer require
preflow/folio` into any Preflow app and you get a **complete, shipped admin** mounted at
`/folio`: the package itself provides the core — dashboard, content-type CRUD, settings,
asset management, user management. **Preflow stands alone without it; adding Folio opens a
whole new world** on top of whatever the app already is.

This mirrors Crelish exactly: `require crelish` into any Yii2 project → a full CMS at
`/crelish` with core views (pages, content CRUD, settings, assets, users). The skeleton's
`app/pages/admin` is **not** the CMS — it is a demo that you *can* hand-build admin pages
with plain Preflow. Folio ships and owns its own admin; it does not reuse `app/pages/admin`.

### The distinctive DX: extension + per-action override

Beyond the shipped core, Folio's signature feature (from Crelish) is the **extension +
override layer**. A project can:

- **Add** new admin controllers/areas, and
- **Override a single core action** by dropping a userland class at a conventional
  location — e.g. shadow the package's `UserController::export` without forking the
  package. Surgical override, no core edits.

In Crelish this was `workspace/crelish/actions/user/export`. Folio does it Preflow-native
via PSR-4 classes resolved before the package default (see Override Mechanism below).

### Why Preflow-native (no SPA framework)

North star is **Craft CMS** (server-rendered, JS only where needed), not Gutenberg.
Preflow already provides a Hotwire/Livewire-class stack — `preflow/components` (PHP +
co-located Twig, lifecycle, actions, fragments, `ErrorBoundary`), `preflow/htmx` (signed
action tokens, 5-layer security gate, SSE, swappable driver), `preflow/form` (auto-form
from validation rules, inline validation). App-feel comes from no-reload fragment swaps +
inline validation + autosave. A client framework is reserved for a small set of JS
"islands" (rich text, drag-reorder) introduced in later specs — not in this skeleton.

### The modeling backbone already exists

`preflow/data` already ships JSON-defined dynamic content types end to end:
`TypeRegistry` loads a `TypeDefinition` from `{models_path}/{type}.json`; `DynamicRecord`
is a runtime record with storage transformers; `DataManager::findType / queryType /
saveType / deleteType` give full validated CRUD across JSON-file and SQLite/MySQL storage;
`QueryBuilder::forType()` filters/searches/paginates. **The app already points
`models_path` at `config/models/` (`config/data.php`).** Subsystem "content modeling +
storage" is therefore done — Folio composes it.

### Native homes (Folio adds almost no filesystem conventions)

Crelish lumped everything into `workspace/` because Yii2 had nowhere else. Preflow already
has idiomatic homes:

| Crelish `workspace/` | Native Preflow home in a Folio app |
|---|---|
| `elements/*.json` + `models/*.php` (dual-file types) | `config/models/*.json` (single file; `DynamicRecord`, no PHP model needed) |
| `widgets/*` (PHP + `views/`) | `app/Components/*` (same pattern, already a Preflow primitive) |
| `crelish/controllers` + `views` (custom admin) | `app/Folio/*` extension namespace (see below) |
| `crelish/sidebar.json` (admin nav) | admin nav registration |
| `components/*.php` (helpers) | `app/` normal autoloaded PHP |
| `hooks/*.php` (lifecycle) | events/listeners (on the Preflow roadmap) |
| `globals/*.json` (singletons) | a content type flagged `singleton` |
| `data/*.json`, `pdf/*` | `app/Seeds`, userland storage |

Frontend templates for rendered content live in **normal userland views** — not hidden in
a special folder (per explicit user direction).

## Goals — the walking skeleton

Prove the full architecture, including the signature override DX, with one thin slice:

> `composer require preflow/folio` → `/folio` serves Folio's **own** admin shell (nav +
> dashboard) → drop `config/models/page.json` → a **Pages** CRUD area appears in that
> shipped admin → create / edit / delete a page through an auto-generated form → the page
> renders at its slug on the public frontend → **and** one shipped core action can be
> overridden by a userland class.

This exercises every seam: package mount + shipped admin → type discovery → storage CRUD →
auto-form → routing precedence → frontend render → action override.

## Non-Goals (later specs)

- Rich text / WYSIWYG, relations, asset/media fields, matrix/blocks
- **Live preview** (draft-aware + surgical iframe) — designed conceptually, built later
- Full settings, asset management, user management UIs (core CMS, but each its own slice)
- The full extension story (adding whole custom admin areas); skeleton proves *override*
  of a single action only
- i18n, versioning/drafts, search; real auth beyond a stub gate
- Analytics, newsletter, PDF — never in core

## Architecture

**`preflow/folio`** ships:

1. **Admin kernel + shell** — mounts all admin routes under a configurable base path
   (default `/folio`). Provides a layout, nav (one entry per discovered type + dashboard),
   and a dashboard landing.
2. **Core content controller(s)** — generic, type-driven list / create / edit / delete,
   composing `data` (`*Type()` CRUD) + `form` (auto-form from `TypeDefinition` rules) +
   `components`/`htmx` (server-driven, inline validation).
3. **Frontend resolver** — lowest-priority catch-all that maps a request path to a content
   record by slug and renders a userland template.
4. **Action resolver** — dispatches admin actions through an override check before the
   package default.

| Concern | Owner | New? |
|---|---|---|
| Type definition (`page.json`) | `data` `TypeRegistry` / `TypeDefinition` | existing |
| Record CRUD + validation | `data` `DataManager::*Type()` | existing |
| Auto-form from type | `form` builder | existing + thin glue |
| Admin mount + shell + nav | `folio` admin kernel | **new** |
| Type-driven CRUD controller | `folio` | **new** |
| Frontend slug resolver | `routing` catch-all + `folio` | existing + thin glue |
| Action override resolver | `folio` | **new (signature DX)** |
| Auth gate | `auth` (stubbed "is admin") | existing, deferred |

### Mount path (collision-safe)

The admin base path is **configurable** (e.g. `folio.path`), default `/folio`. A project
already using that path sets another. Admin routes register under this base at high
priority and never collide with app routes.

### Routing precedence (Crelish interception, done cleanly)

Preflow's router matches **static > dynamic > catch-all**. Folio's frontend slug resolver
registers as the **lowest-priority catch-all**.

- **Without Folio:** file-based `app/pages/` + attribute controllers — a normal app.
- **With Folio:** those still win; unmatched requests fall through to slug resolution.
  Admin routes under `/folio` are ordinary high-priority routes.

### Override mechanism (minimal slice)

Folio core controllers dispatch each action through an `ActionResolver`. Before running a
package default, the resolver checks for a userland override class by convention:

```
App\Folio\Overrides\{Controller}\{Action}  →  app/Folio/Overrides/{Controller}/{Action}.php
```

If present (implementing a small `OverridableAction` interface), it runs instead of the
package default; otherwise the default runs. PSR-4 classes, not path-string magic — no
core edits, no forking. The skeleton proves this for **one** shipped action end to end;
the full extension story (whole custom areas, nav contributions) is a later spec. The
exact interface/namespace is provisional and open to refinement in the override spec.

### Conventions (skeleton)

| Thing | Location / value |
|---|---|
| Admin mount path | `/folio` (configurable via `folio.path`) |
| Type definitions | `config/models/*.json` (existing `data` `models_path`) |
| Frontend template per type | userland views (app's normal view location), keyed by type |
| Action overrides | `app/Folio/Overrides/{Controller}/{Action}.php` |
| Demo type | `page` |

## Data Flow

**Admin — edit cycle:**

1. `GET /folio` → Folio shell; nav lists discovered types from `config/models/`.
2. `GET /folio/page` → action dispatched via `ActionResolver` → `queryType('page')` → list.
3. `GET /folio/page/{id}/edit` (or `/new`) → `findType` → `form` renders from
   `TypeDefinition` rules.
4. Submit → `validateDynamicRecord` → `saveType`; on error re-render with `ErrorBag`
   (inline validation via the hypermedia driver); on success HTMX swap / redirect.

**Frontend — render cycle:**

1. Request unmatched by `app/pages/` or controllers → Folio catch-all resolver.
2. Look up a `page` whose `slug` matches the path (only `published`).
3. Hit → render the userland `page` template with the `DynamicRecord`'s fields → 200.
4. Miss → 404.

**Override:**

`GET /folio/<some-core-action>` → `ActionResolver` finds `App\Folio\Overrides\...` → runs
the userland action instead of the package default.

## Demo Content Type

`config/models/page.json`:

| Field | Type | Notes |
|---|---|---|
| `title` | string | required |
| `slug` | string | required |
| `body` | text | textarea in the form |
| `status` | string | select: `draft` / `published` |

**Storage:** JSON-file for the demo type (zero-config, no migration — strongest "drop in
and go" proof). Identical `DataManager` API swaps to SQLite via the type's `storage` value.

## Error Handling

- Admin errors → components `ErrorBoundary` (dev panel / prod fallback).
- Malformed type JSON → skipped, logged, non-fatal.
- Frontend slug miss → 404.
- Validation failure → re-render form with `ErrorBag`, no save.
- Missing or non-`OverridableAction` override class → fall back to package default.

## Testing

Following the `testing` package and TDD:

- **Mount/shell:** admin reachable at the configured base path; changing the config moves
  it; nav lists discovered types.
- **Type discovery:** valid `config/models/*.json` appears as a CRUD area; malformed JSON
  ignored and logged; empty models dir → no type areas, shell still loads.
- **CRUD round-trip:** create → list → edit → save → delete against JSON-file storage;
  invalid record rejected by validation.
- **Frontend resolver:** published slug → renders template with field values; unknown slug
  → 404; `draft` → not resolvable.
- **Precedence:** an `app/pages/` route and a controller route both win over a Folio slug
  of the same path.
- **Override:** with an `App\Folio\Overrides\...` class present, that action runs; absent,
  the package default runs.

## Future Specs (decomposition, build order)

1. **Field / editor system** — rich text (TipTap island), relation picker, asset picker,
   matrix/blocks; the JS-island pattern.
2. **Extension layer (full)** — adding whole custom admin areas + nav contributions;
   finalize the override interface.
3. **Live preview** — draft-aware (render in-progress form state, no save) + surgical
   (postMessage debounced fragment swap into a resizable iframe for true responsive
   preview). Keeps the iframe for style isolation + viewport simulation (Shadow DOM cannot
   simulate viewport width). Fixes both Crelish failures.
4. **Core CMS areas** — settings, asset management, user management (each a slice).
5. **Add-ons** — i18n, versioning/drafts, search. Always optional, never in core.
