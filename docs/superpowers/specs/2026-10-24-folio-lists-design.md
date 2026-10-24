# Folio Lists — Search, Filters, Sorting, Paging, Bulk Actions — Design

**Date:** 2026-10-24
**Status:** Approved in conversation, not started.
**Context:** Phase 2, step 2 of the BG Genius on Folio plan
(`bggenius-preflow/docs/superpowers/specs/2026-10-24-bggenius-on-folio-design.md`).
Current framework version: v0.27.1.

## Why

Folio lists have no search, sorting, filters, paging or bulk actions, and they load every
record of a type into one page. The owner's previous CMS (Crelish on Yii2,
`../giantbits/yii2-crelish`) had all of that on every list, and the owner relied on it daily.
The sites on Folio are still small, but the board game list on spielegesellschaft has
1012 entries, and an asset list at ~10 images per game grows past 10,000. Lists have to stay
fast at tens of thousands of records.

Anti-goal: Folio must not get clunky. A list shows only the controls someone asked for;
search and sorting are the only things every list gets automatically.

## Two parts, in this order

1. **Data package** (`packages/data`): relations declared on types, loose search that reaches
   through relations, a cache for JSON storage, and delete plans (detach / cascade / restrict).
   Search is a storage concern: each storage driver searches in its own way.
2. **Folio** (`packages/folio`): the list screen, the relation picker, bulk actions and Excel
   export, built only on what the data package offers.

Each part is released and checked against the three Folio sites' test suites
(smeyer.me, smart-stack.works, holz-objekte.org) before the next.

## Part 1 — Data package

### Relations

- A relation is declared on a field of a type. Folio's model JSON already does this, and the
  data package reads the same declaration, so existing models keep working unchanged:
  `"relation": {"to": "designer", "multiple": true, "labelField": "name", "orderBy": "name"}`.
- New key `"onDelete"`: what happens to records pointing at a deleted target.
  - `"detach"` (default): the deleted id is removed from the pointing field (a single relation
    becomes empty, a multiple one loses that id). Today a deleted target leaves a dead id;
    this fixes that.
  - `"cascade"`: the pointing records are deleted too (and their own relations apply in turn).
  - `"restrict"`: the delete is refused while any record points at the target.
- Stored values stay as they are today: a single relation is the target id as a string, a
  multiple relation a JSON array string of ids (`RelationFieldType::toStorage`).
- The data package must be able to answer "which fields of which types point at type X"
  (inbound relations), which means enumerating all type definitions, not only loading them
  on demand as `TypeRegistry` does now.

### Loose search

- `Query::search(term, fields)` stays as it is: one term, matched as a substring (exact
  phrase), OR across fields.
- New `searchLoose(term, fields)` on `Query` and `QueryBuilder`:
  - The term is split on whitespace. Every word must match (AND); a word matches if it is a
    case-insensitive substring of at least one of the fields (OR). "night zo" finds
    "Night at the Zoo"; "vita cer" finds "Vital Lacerda".
  - Fields default to every text-like field of the type plus its declared relations. A field
    can opt out with `"searchable": false` in its definition (e.g. internal notes).
  - Through a relation, a word matches when it matches the target record's label field (or
    its text fields; the builder decides how wide, label field at least). One level deep: a
    target's own relations are not followed.
  - Translatable fields match in the current content locale, as the existing localization
    handling in `Query` / `QueryCompiler` already does for sorting.
- Each storage driver implements it:
  - PDO drivers (SQLite, MySQL): `LIKE` conditions; for relations, resolve the matching target
    ids first (via the target type's own driver, which may be a different storage) and then
    match them in the pointing column. How a JSON array column is matched is the builder's
    call (`json_each` on SQLite, JSON functions or `LIKE '%"id"%'` on MySQL); it must work on
    the MySQL support added in `2026-10-23-mysql-support-design.md`.
  - JSON file driver: in memory against the cache below.
- Name is `searchLoose`, not `searchFuzzy`: fuzzy usually means typo tolerance ("nigth"),
  which is a possible later feature under that name.

### JSON storage cache

- One cache file per JSON-stored type holding all its records, so a query reads one file
  instead of decoding every record file.
- Rebuilt on every save and delete through the driver. Also invalidated when the type's
  folder changed (newest mtime of the folder or its files), so records changed by a git pull
  or by hand are picked up.
- Lives under the app's cache directory. Later the cache package (Phase 2 step 4) lists and
  clears it like any other cache; nothing to build for that now beyond a clear method and a
  known location.

### Delete plans

- `deletePlan(type, ids): DeletePlan` — what deleting these records would do, without doing it:
  records cascaded (per type, with counts), records detached (per type, with counts), and
  restrictions (per type, with counts). Works for many ids at once and follows cascades
  recursively, so a bulk delete gets one combined answer.
- Deleting carries out the plan. If the plan has restrictions, the delete is refused.
- Whether execution is atomic is the builder's call; at least: nothing is deleted if the plan
  is restricted, and SQL deletes on one connection run in a transaction.
- **Who executes (decided):** the data package computes the plan and offers a plain executor
  for apps without Folio. In Folio, Folio drives execution record by record through its own
  delete and save paths, so a cascaded record is deleted exactly like a single admin delete
  (`BeforeDelete`, `dm->deleteType`, `publisher->forget()`, `AfterDelete`) and a detach is a
  save of the pointing record (`BeforeSave` / `AfterSave`). All `BeforeDelete` hooks of the
  whole plan run first; one refusal cancels the whole operation before anything is deleted.
- **Publishing types (decided):** detach edits the working copy only, like any save; the
  published snapshot keeps the old id until the next publish. Frontend rendering of a
  relation must therefore skip ids whose target no longer exists (verify, fix if not).
  Restrict counts pointers in the working copy and in the published snapshot.
- **Relations inside blocks (decided):** only relations declared on type fields count as
  inbound relations. Relation fields inside blocks and matrix placements are not scanned;
  their dead ids are skipped at render time like above. Revisit if it bites.

### Field kinds (decided)

- Which field types are text-like (searchable) and which are relations is not hard-coded in
  the data package. The data package defines a small registry of field kinds; Folio fills it
  from its `FieldTypeRegistry` (so extension field types can take part). Apps without Folio
  get sensible defaults (`string`, `text` text-like; `relation` relational).
- `searchable` today is opt-in and is the default field list of `search()`; that stays
  unchanged. `searchLoose` uses its own default: all text-like fields and relations, minus
  fields that explicitly say `"searchable": false` (so the flag becomes tri-state: unset,
  true, false). PHP `Model` classes with `#[Field(searchable: …)]` follow the same rule.
- Inbound-relation lookup includes types defined at runtime by extensions (`define()`,
  `"extends": true`). Delete plans are only computed after Folio has booted, including in
  console commands (`BootsApplication`).

## Part 2 — Folio

### List screen (layout B from the mockup)

- **Header:** type label, Export button (when available), "+ New".
- **Search box:** always present. Uses `searchLoose`. Searches after a short pause while
  typing; no Enter needed. Matches are highlighted in the cells.
- **"+ Filter":** offers only the filters the type declares. A chosen filter becomes a
  removable chip ("Status: Coming soon ✕"). Controls follow the field type:
  select → its options; boolean → yes / no / any; relation → the related records (with a
  search once there are many, using the picker below); date → from–to range.
- **Table:** tick column, then the list columns. Every column header sorts (asc/desc) except
  relation columns (decided: not sortable in this round; sorting by a target's label across
  storages is a later feature). Select columns sort by stored value. On
  `orderable` types the manual order stays the default until a column is sorted. Relation
  fields may be list columns and show labels, loaded in one batch per page (not per row).
- **Paging:** 50 per page, "1–50 of 1012", page links. A per-page setting may come later;
  keep 50 in one place.
- **URL holds the view:** search, filters, sort and page are query parameters. Coming back
  from a record returns to the same view; the URL can be shared.
- **Bulk bar:** ticking rows replaces nothing but shows a bar above the table: "3 selected",
  then the actions, then "Clear". Changing search or filters clears the selection, so a bulk
  action never touches rows the user cannot see. A "select all N matching" across pages is
  not required.
- Works under the strict nonce CSP (no inline handlers or styles without nonces), like the
  rest of the admin.

### Configuration in the model's `list` block

```json
"list": {
  "columns": ["title", "designer", "status"],
  "filters": ["status", "designer"],
  "bulk": ["status"]
}
```

- `filters`: opt-in. Only these fields get a filter.
- `bulk`: opt-in. Each field gets a "Set {label} to…" bulk action (select and boolean fields).
- `columns`, `orderable`, `sort` keep their current meaning.

### Bulk actions

- **Set field** (from `bulk`): goes through the same save path as the edit form —
  validation, `BeforeSave` / `AfterSave` hooks, publishing (for publishing types it changes
  the working copy, like a form save would; offering a bulk "Publish" is the builder's call).
- **Type-specific actions:** a new extension attribute next to `RecordAction`, e.g.
  `#[BulkAction(name, label, permission, confirm?)]` on a method
  `fn(list<DynamicRecord> $records, ServerRequestInterface $request): ?ResponseInterface`.
- **Delete:** on every type, guarded by the type's delete permission. Before deleting, a
  confirmation shows the delete plan: "can't be undone · also deletes 12 games · 3 pages lose
  a link". If the plan is restricted, or the type's `BeforeDelete` hook refuses
  (`Customization\Refused`), nothing is deleted and the reason is shown. The single-record
  delete in the edit form uses the same plan and confirmation, so the two cannot drift apart.
- **Export to Excel:** a real `.xlsx` via OpenSpout (`openspout/openspout`, streaming, flat
  memory). Optional: a `suggest` in Folio's composer.json; the Export button only appears when
  the library is installed. Exports the ticked rows, or the whole filtered and sorted list if
  none are ticked. Columns are the list's columns; relations written as labels, not ids.
- Every bulk action checks the same permissions as its single-record counterpart for each
  record.

### Relation picker

- The edit form's relation picker currently loads every target record. With 1012 games that
  is too much. It pages and searches through the data package instead (same `searchLoose`),
  keeping today's behaviour for small target types where possible.

## Out of scope

- Folio's asset library list: the media package (Phase 2 step 5) replaces it and builds its
  list on this same base.
- Typo-tolerant ("fuzzy") search.
- A configurable page size.
- Reports and certificates (Phase 2 step 7).

## Left to the builder

- Exact class and method names beyond `searchLoose`, `deletePlan`, `onDelete`, `filters`,
  `bulk`, `BulkAction`.
- How the list updates without full reloads (htmx partials with pushed URLs fit the admin).
- The JSON array matching per SQL dialect, and the cache file format.
- Tests per package, following the existing test layout; the release follows the existing
  release checklist (`docs/superpowers/plans/2026-10-2x-release-0.2x-checklist.md`).
