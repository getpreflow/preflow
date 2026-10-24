# Folio Lists Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every Folio list gets loose search (also through relations), opt-in filters, column sorting, paging (50), and bulk actions (set field, type actions, delete with a delete plan, Excel export); the data package gains declared relations, `searchLoose`, a JSON storage cache and delete plans. Released as v0.28.0 (data, Part 1) and v0.29.0 (Folio, Part 2).

**Architecture:** Part 1 lives in `packages/data` (+ one wiring line in `packages/core`): a field-kind registry, relations read from the existing `"relation"` field config, a generic OR-group condition on `Query` that every driver compiles (`CONTAINS`, `IN`, `HAS`), `QueryBuilder::searchLoose()` built on it, a per-type cache file in `JsonFileDriver`, and `DeletePlanner`. Part 2 lives in `packages/folio`: a `ListQuery` that turns the request into a data query, a rebuilt `list.twig` (layout B) with a small admin.js module, a remote mode for the relation picker, `RecordDeleter` shared by single and bulk delete, bulk routes, `#[BulkAction]`, and an optional OpenSpout export.

**Tech Stack:** PHP 8.4, SQLite + MySQL/MariaDB 10.6, PHPUnit 11, Twig, plain JS (no build), OpenSpout (optional).

**Spec:** `docs/superpowers/specs/2026-10-24-folio-lists-design.md`

## Global Constraints

- Names fixed by the spec: `searchLoose`, `deletePlan`, `"onDelete"` (`"detach"` default | `"cascade"` | `"restrict"`), list keys `"filters"` and `"bulk"`, attribute `#[BulkAction]`.
- `search()` keeps its exact behaviour and its default field list (`searchable: true` fields). Existing callers see no change.
- Relation storage stays as today: single = id string, multiple = JSON array string (`RelationFieldType::toStorage`).
- Loose search: split on whitespace; every word must match (AND); a word matches as a case-insensitive substring in at least one field (OR); relations one level deep, matched on the target's label field and text fields.
- `%`, `_` and the escape character in user input match literally. Field names from requests are whitelisted against the type before they reach a query.
- Page size 50, defined once (`ListQuery::PER_PAGE`).
- Strict nonce CSP: no inline handlers or styles; new JS goes in `assets/admin.js`; `AdminJsTest` / `CspGuardTest` stay green.
- MariaDB 10.6 is the MySQL floor (no `JSON_OVERLAPS`).
- Every new admin route has a rule in `FolioPermissionMiddleware::RULES`; bulk actions check the single-record permission for the type.
- Release gates: `vendor/bin/phpunit`, `composer test:mysql`, and the suites of smeyer.me, smart-stack.works and holz-objekte.org green; deprecation counts don't grow. Releases (merge, push, tag) only with the owner's go-ahead.

## Review Focus

- A search word containing `%`, `_`, `!`, quotes or umlauts → literal match, no SQL error, same results on SQLite, MySQL and JSON (test in Task 2).
- Crafted list URLs naming unknown fields in `sort`, `f[...]`, or a negative / huge `page` → ignored or clamped, never a SQL error (test in Task 7).
- A relation from a SQL type to a JSON type (and back) in search, filters and delete plans → works across storages (tests in Tasks 3 and 5).
- A bulk delete where one cascaded record's `BeforeDelete` refuses → nothing at all is deleted (test in Task 10).
- Searching a translatable field while the admin's content locale isn't the default → matches that locale's text (test in Task 3).

---

## Part 1 — Data package (v0.28.0)

### Task 1: Field kinds, relations and listing all types
**Files:** new `packages/data/src/FieldKinds.php`, `packages/data/src/Relation.php`, `packages/data/src/OnDelete.php` (enum); modify `src/TypeRegistry.php`, `src/DataManager.php`, `src/TypeFieldDefinition.php`, `src/Attributes/Field.php`, `src/ModelMetadata.php`; tests `tests/FieldKindsTest.php`, `tests/RelationTest.php`, `tests/TypeRegistryTest.php`.
**Produces:**
- `FieldKinds`: `text(string ...$types): void`, `relation(string ...$types): void`, `isText(string $type): bool`, `isRelation(string $type): bool`. Defaults: text `string`, `text`; relation `relation`.
- `enum OnDelete: string { Detach = 'detach'; Cascade = 'cascade'; Restrict = 'restrict'; }`
- `final readonly class Relation(string $type, string $field, string $target, bool $multiple, OnDelete $onDelete, ?string $labelField)` — read from a field whose kind is relation and whose `config['relation']['to']` is set; unknown `onDelete` values throw `\InvalidArgumentException` naming type and field.
- `TypeRegistry::keys(): list<string>` — model files that are types of their own (not `"extends": true`) plus types added by `define()`, sorted.
- `DataManager::fieldKinds(): FieldKinds` (one lazily created instance), `relationsOf(string $type): list<Relation>`, `relationsTo(string $target): list<Relation>` (scans `keys()`).
- `TypeFieldDefinition::$searchable` becomes `?bool` (null = not said); `#[Field(searchable: ?bool = null)]`. `TypeDefinition::$searchableFields` and `ModelMetadata::$searchableFields` keep meaning "explicitly true".
- [ ] Tests: defaults and registration; relation parsed from `{"relation": {"to": "designer", "multiple": true, "onDelete": "cascade"}}`; default `Detach`; bad `onDelete` throws; `keys()` includes a defined type and excludes an `extends` file; `relationsTo('designer')` finds pointers in two types; `searchable` unset vs false vs true. → FAIL → implement → PASS → commit `feat(data): field kinds, declared relations, list all types`.

### Task 2: OR-groups in Query with CONTAINS, IN and HAS
**Files:** `src/Query.php`, `src/QueryBuilder.php`, `src/Driver/QueryCompiler.php`, `src/Driver/Dialect.php`, `src/Driver/SqliteDialect.php`, `src/Driver/MysqlDialect.php`, `src/Driver/JsonFileDriver.php`; tests `tests/QueryTest.php`, `tests/Driver/QueryCompilerTest.php`, `tests/Driver/SqliteDriverTest.php`, `tests/Driver/JsonFileDriverTest.php`, `tests/Driver/MysqlDriverTest.php` (MySQL group).
**Produces:**
- `Query::whereAny(list<array{field: string, operator: string, value: mixed}> $conditions): self` — the conditions are OR'd; each call is one group AND'd with all other wheres and groups. `Query::getAnyGroups(): list<list<array{...}>>`. `QueryBuilder::whereAny(array $conditions): self` passes through.
- Operators inside groups: `=`, `CONTAINS` (case-insensitive substring of a string), `IN` (value is a list; empty list matches nothing), `HAS` (column holds a JSON array of ids or a single id string; true if any of the given ids is in it; empty list matches nothing).
- SQL: `CONTAINS` → `{col} LIKE ? ESCAPE '!'` with `%`, `_`, `!` escaped by `!`; `IN` → `{col} IN (?,…)`; `HAS` → new `Dialect::jsonArrayHasAny(string $quotedColumn, int $count): string` and `Dialect::jsonArrayBinding(string $id): string`. SQLite: `({col} IN (…) OR EXISTS (SELECT 1 FROM json_each(CASE WHEN json_valid({col}) THEN {col} ELSE '[]' END) WHERE value IN (…)))`. MariaDB 10.6: `{col} IN (…)` OR one `(CASE WHEN JSON_VALID({col}) THEN JSON_CONTAINS({col}, ?) ELSE 0 END)` per id, binding `json_encode($id)`. Translatable columns go through the existing `column()` localization. Groups apply to `compile()` and `compileCount()` alike.
- JSON driver: same semantics in memory (`mb_stripos`, decoded arrays), using the existing localized `value()`.
- [ ] Tests (each on SQLite and JSON; MySQL in the MySQL group): `CONTAINS` case-insensitive; `%`, `_`, `!` and `'` match literally (`"50%"` does not find `"500"`); umlaut word finds same-case umlaut text; `IN` and empty `IN`; `HAS` on `'["a","b"]'`, on `'a'`, on `''`, on invalid JSON; two groups AND'd; count equals rows. → FAIL → implement → PASS → commit `feat(data): OR-groups with CONTAINS, IN and HAS`.

### Task 3: searchLoose
**Files:** `src/QueryBuilder.php`, `src/DataManager.php` (`queryIn` / `query` pass `$this`); tests new `tests/SearchLooseTest.php`.
**Interfaces:** Consumes Task 1 (`FieldKinds`, `relationsOf`) and Task 2 (`whereAny`).
**Produces:** `QueryBuilder::searchLoose(string $term, ?array $fields = null): self`; `QueryBuilder::forType(StorageDriver $driver, TypeDefinition $typeDef, ?DataManager $dm = null)`; `public const RELATION_MATCH_LIMIT = 1000`.
- Default fields: dynamic types → fields whose kind is text or relation and whose `searchable` is not `false`; typed models → `string` properties not marked `searchable: false`.
- Per word one `whereAny` group: `CONTAINS` on each text field, plus per relation field: ids of target records where the word matches the target's `labelField` (else its first string field) or any of its text fields (one level; a `whereAny` query on the target, limited to `RELATION_MATCH_LIMIT` ids), then `IN` (single) or `HAS` (multiple). Without a `DataManager`, relations are skipped. Blank term = no condition.
- [ ] Tests: "night zo" finds only "Night at the Zoo"; "vita cer" finds games whose designer relation points at "Vital Lacerda" (multiple relation) and whose single relation does; source SQLite → target JSON and source JSON → target SQLite; `searchable: false` field excluded; translatable field matched in a non-default locale via `->locale('de')`; `search()` unchanged (existing tests). → FAIL → implement → PASS → commit `feat(data): searchLoose — every word narrows, through relations`.

### Task 4: JSON storage cache
**Files:** `src/Driver/JsonFileDriver.php`, `packages/core/src/Application.php` (pass `cacheDir: basePath('storage/cache/data')`); tests `tests/Driver/JsonFileDriverTest.php`.
**Produces:** `JsonFileDriver::__construct(string $basePath, ?string $cacheDir = null)`; `clearCache(?string $type = null): void`; `cacheDir(): ?string`. Without a cache dir, behaviour is today's.
- One file per type `{cacheDir}/json-{type}.php` (or `.json`; builder's call) holding all records plus a signature: record count, newest mtime of the files and the directory. `findMany` uses it when the signature matches, rebuilds otherwise; `save` and `delete` drop it; writes are atomic (temp + rename).
- [ ] Tests: second query reads the cache (records unchanged); a save, a delete, a file added outside the driver, and a file rewritten outside the driver (touch with a later mtime) all show up; `clearCache()`; no cache dir → no files written. → FAIL → implement → PASS → commit `feat(data): cache JSON-stored types for queries`.

### Task 5: Delete plans
**Files:** new `src/DeletePlanner.php`, `src/DeletePlan.php`; `src/DataManager.php` (`deletePlan`, `executeDeletePlan`); tests new `tests/DeletePlanTest.php`.
**Interfaces:** Consumes Task 1 (`relationsTo`) and Task 2 (`IN` / `HAS` to find pointers).
**Produces:**
- `DataManager::deletePlan(string $type, list<string> $ids, ?\Closure $copies = null): DeletePlan` — `$copies` is `fn(TypeDefinition $def): list<TypeDefinition>`, extra definitions holding copies of a type's records (Folio passes the published shadow); they count for `restrict` only.
- `DeletePlan`: `deletes(): array<string, list<string>>` (type → ids, roots included, cascades followed recursively, no duplicates, cycles safe); `detaches(): list<array{type: string, id: string, field: string, remove: list<string>}>`; `restrictions(): array<string, int>` (type → count of pointing records); `restricted(): bool`; `counts(): array{deleted: array<string,int>, detached: array<string,int>, restricted: array<string,int>}` excluding the roots from `deleted`.
- `DataManager::executeDeletePlan(DeletePlan $plan): void` — refuses (`\RuntimeException`) a restricted plan; applies detaches (load, remove ids, `saveType(validate: false)`), then deletes; one SQL transaction per PDO driver involved.
- [ ] Tests: detach default removes the id from a multiple and empties a single relation; cascade two levels deep; cycle A↔B terminates; restrict counted (also from a `$copies` definition) and execution refused with nothing changed; ids of several roots merged; cross-storage pointer found. → FAIL → implement → PASS → commit `feat(data): delete plans — detach, cascade, restrict`.

### Task 6: Release 0.28.0 (data)
**Files:** `packages/data/README.md` (relations, `onDelete`, `searchLoose`, `whereAny`, cache, delete plans), `ROADMAP.md`, `packages/core/composer.json` / Folio `conflict` on `preflow/data <0.28` where Part 2 needs it (added in Part 2), `docs/superpowers/plans/2026-10-24-release-0.28-checklist.md` (same shape as 0.27's).
- [ ] Full suite, `composer test:mysql`, three site suites (delete `storage/cache/folio-extensions.cache` first) → record counts in the checklist → commit `chore: prepare 0.28.0` → ask the owner before merge, push and tag.

## Part 2 — Folio (v0.29.0)

### Task 7: Field kinds from Folio and the list query
**Files:** new `packages/folio/src/Field/SearchableText.php` (marker interface), `src/Content/ListQuery.php`; modify `src/Field/Types/StringFieldType.php`, `TextFieldType.php`, `RichTextFieldType.php`, `SelectFieldType.php` (implement the marker), `src/FolioServiceProvider.php` (fill `$dm->fieldKinds()` from `FieldTypeRegistry`: marker → text, `relation` → relation), `src/Content/TypeListing.php` doc for `filters` / `bulk`; tests `tests/Content/ListQueryTest.php`.
**Produces:** `ListQuery::fromRequest(ServerRequestInterface $request, TypeDefinition $def, array $listConfig, string $locale): ListQuery` with readonly `string $q`, `array $filters` (field → value or `['from' => , 'to' => ]`), `?string $sort`, `bool $desc`, `int $page`; `apply(QueryBuilder $qb): QueryBuilder` (searchLoose, filters, sort or `ListOrder::apply` when unsorted, paging); `public const PER_PAGE = 50`; `query(): array` (the URL parameters, for links).
- URL: `q`, `f[field]=value` (date: `f[field][from]`, `f[field][to]`), `sort=field` / `sort=-field`, `page`. Filters only for fields in `list.filters`; select → `=`, boolean → `=` `'1'`/`'0'`, relation → `IN` / `HAS`, date → range. Sort only on list columns that aren't relations; select sorts by stored value; number fields numeric. Unknown fields dropped; page clamped to ≥ 1 and to the last page.
- [ ] Tests: each filter kind; unknown `sort`/`f[...]` ignored; `page=-3` and `page=999` clamped; orderable type keeps manual order until sorted; `query()` round-trips. → FAIL → implement → PASS → commit `feat(folio): list query from the request`.

### Task 8: The list screen
**Files:** `src/Http/AdminController.php` (`list()` uses `ListQuery`, `paginate`, relation columns with labels loaded in one batch per page; `LIST_TYPES` gains `relation`), `templates/admin/list.twig`, new `templates/admin/_list_results.twig` (table + pager, the swappable region), `assets/admin.js` (list module), `assets/admin.css`; tests `tests/Http/AdminControllerTest.php`, `tests/Integration/FolioAppTest.php`, `tests/Assets/AdminJsTest.php`.
**Produces:** the layout B screen from the spec: search box, "+ Filter" menu listing `list.filters`, chips with ✕, sortable headers (▲/▼), highlighted matches (`<mark>` from escaped text, server-side), "1–50 of N", page links, empty-result message distinct from "no records yet". Works without JS as a GET form. With JS: input debounced 300 ms, fetch the URL with `?_partial=1`, swap `[data-folio-list-results]`, `history.replaceState`; back/forward restore by reload.
- [ ] Tests: list paginates (51 records → 50 + pager); `?q=` narrows; chips rendered for active filters; highlight escapes HTML in values; `_partial=1` renders only the results template; real-Twig integration render under `strict_variables`; AdminJsTest finds no inline handlers. → FAIL → implement → PASS → commit `feat(folio): search, filters, sorting and paging on every list`.

### Task 9: Relation picker with remote search
**Files:** `src/Field/Types/RelationFieldType.php`, new route `GET {prefix}/_relation/{type}` → `AdminController@relationOptions` (rule `{type}.view` or the list's rule — use the list's), `assets/admin.js`; tests `tests/Field/RelationFieldTypeTest.php`, `tests/Http/AdminControllerTest.php`.
**Produces:** up to `RelationFieldType::INLINE_LIMIT = 200` target records the picker stays as today; above it the editor renders only the selected items plus a search input that queries `/_relation/{type}?q=&page=` (JSON: `{items: [{id, label}], more: bool}`, 20 per page, `searchLoose` on the target). The list's relation filter uses the same endpoint.
- [ ] Tests: ≤ 200 unchanged markup; > 200 renders selected only; endpoint searches, pages, 404 for unknown type, permission rule present. → FAIL → implement → PASS → commit `feat(folio): relation picker searches large types`.

### Task 10: RecordDeleter and the delete confirmation
**Files:** new `src/Content/RecordDeleter.php`; `src/Http/AdminController.php` (`destroy` uses it; new `POST {prefix}/{type}/_delete-plan` → `deletePlan`, JSON summary), `src/Http/FolioPermissionMiddleware.php`, `templates/admin/list.twig` + form delete button (`data-delete-plan`), `assets/admin.js` (dialog); tests new `tests/Content/RecordDeleterTest.php`, `tests/Http/AdminControllerTest.php`.
**Interfaces:** Consumes `DataManager::deletePlan` (Task 5).
**Produces:** `RecordDeleter::plan(string $type, list<string> $ids): DeletePlan` (passes `ShadowTypes::published` as copies for publishing types); `RecordDeleter::delete(string $type, list<string> $ids): void` throwing `Customization\Refused` with the reason. Order: build plan → refuse if restricted ("Can't delete: 2 progress records still point here.") → run `BeforeSave` on each detached record and `BeforeDelete` on each deleted record (any `Refused` / `ValidationException` cancels everything) → detach via the normal save path (publishing types: `Publisher::saveDraft($record, null)`; others: `dm->saveType`) → delete each (`dm->deleteType`, `publisher->forget` when publishing) → `AfterSave` / `AfterDelete` hooks. Summary copy: "This can't be undone." plus "Also deletes 12 games." / "3 pages lose a link." per type label.
- [ ] Tests: single delete still works and redirects as before; refusal from a cascaded record's `BeforeDelete` leaves everything in place; restrict counts a link that exists only in the published snapshot; detach on a publishing type changes the working copy only; summary endpoint text. → FAIL → implement → PASS → commit `feat(folio): delete through a delete plan, with a summary before confirming`.

### Task 11: Bulk actions
**Files:** new `src/Extension/BulkAction.php`; `src/Extension/Customization/Customizations.php` (collect `bulk` like `actions`, same name/permission validation, `bulkActions(string $type): list<array{...}>`, `bulkAction(string $type, string $name): ?array`); `src/Http/AdminController.php` (`POST {prefix}/{type}/_bulk/{name}` with `ids[]`), `FolioPermissionMiddleware::RULES` (`{type}.edit`; delete → `{type}.delete`), `templates/admin/_list_results.twig` (tick column, select-all on page), `list.twig` (bulk bar), `assets/admin.js`; tests `tests/Extension/CustomizationTest.php`, `tests/Http/AdminControllerTest.php`.
**Produces:**
- Built-in names: `delete` (through `RecordDeleter`), `set-{field}` for each field in `list.bulk` (select / boolean only; value from the bulk bar's menu; saved through the same path as the form: `BeforeSave`, validation, publishing types save the working copy, `AfterSave`), `export` (Task 12).
- `#[BulkAction(name, label, permission, confirm = null)]` on `fn(list<DynamicRecord> $records, ServerRequestInterface $request): ?ResponseInterface`; null → back to the list (same query) with "{label}: done (N)".
- Selection clears when search, filters or page change. Ids not of this type or not found are ignored.
- [ ] Tests: set status on three records (one invalid → its error reported, others saved); an extension bulk action receives the records; permission missing → 403; unknown name → 404; duplicate bulk names across extensions → `ExtensionException`. → FAIL → implement → PASS → commit `feat(folio): bulk actions — set field, extension actions, delete`.

### Task 12: Excel export
**Files:** new `src/Content/ListExport.php`; `packages/folio/composer.json` (`suggest`: `openspout/openspout ^4`), root `composer.json` require-dev `openspout/openspout`; `src/Http/AdminController.php` (`GET {prefix}/{type}/_export` with the list query and optional `ids[]`), `FolioPermissionMiddleware::RULES` (the list's rule); tests new `tests/Content/ListExportTest.php`.
**Produces:** `ListExport::available(): bool` (`class_exists(\OpenSpout\Writer\XLSX\Writer::class)`); `ListExport::write(TypeDefinition $def, array $columns, iterable $records, string $path): void`. Rows streamed in pages of 500; header row bold; numbers and dates as typed cells; relations as labels; translatable fields in the content locale. Ticked ids → those records, else the whole filtered and sorted list. Filename `{type}-{Y-m-d}.xlsx`. The Export button (header and bulk bar) only renders when `available()`.
- [ ] Tests: the file opens with OpenSpout's reader and holds header + rows in list order; ids limit the rows; relation label written; button hidden when unavailable (fake via a seam). → FAIL → implement → PASS → commit `feat(folio): export lists to Excel`.

### Task 13: Docs and release 0.29.0
**Files:** `packages/folio/README.md` (list config `filters` / `bulk`, `onDelete`, `#[BulkAction]`, export add-on), `ROADMAP.md`, Folio `conflict` `preflow/data <0.28`, `docs/superpowers/plans/2026-10-24-release-0.29-checklist.md`.
- [ ] Full suite, `composer test:mysql`, three site suites; check one site's list in the browser (search, a filter, sort, page, a bulk set, a delete summary) → record in the checklist → commit `chore: prepare 0.29.0` → ask the owner before merge, push, tag and site deploys.
