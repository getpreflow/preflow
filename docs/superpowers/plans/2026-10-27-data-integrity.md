# Data Integrity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Saving null clears a column on every driver, fields can be unique (validated and enforced by the database), record tables get real column types, and a separate command converts existing tables safely (check first, backup, convert, re-check).

**Architecture:** The data package owns everything: drivers (clearing, safe upsert), `ColumnKind` and the field-type → kind mapping (`FieldKinds`), typed `RecordTableSync` with a `RecordTables` registry of every record table, unique validation plus the database index, and the drift warning; existing tables are converted by a one-time script in the monorepo's `tools/` (owner, 2026-10-27: not a framework command). Folio's field types declare their column kind through an optional interface and Folio registers its tables (with `__published` typed, `__revisions` text); codes and members declare their unique fields.

**Tech Stack:** PHP 8.4, PDO (SQLite, MySQL/MariaDB 10.6+), PHPUnit, the Preflow monorepo (`packages/data`, `validation`, `devtools`, `folio`, `codes`, `members`, `media`).

**Spec:** `docs/superpowers/specs/2026-10-27-data-integrity-design.md`

## Global Constraints

- PHP 8.4. Breaking changes allowed in 0.x; every consumer in the monorepo migrated in the same release (target v0.42.0).
- SQLite and MySQL/MariaDB 10.6+ both; every SQL behaviour gets a test in the MySQL group (`composer test:mysql`, `#[Group('mysql')]`, skipped without the MySQL env) as well as SQLite.
- `preflow migrate`'s sync **never** alters an existing column and never adds a unique index to an existing column (migrate stays additive while the previous release serves).
- The conversion is a separate command; if any value doesn't fit or any unique field has duplicates, it changes nothing and lists every failure (table, record id, field, value, reason).
- Empty strings in number, date and yes/no columns become NULL on conversion and on save; empty text stays `''`.
- Yes/no accepts only `''`, `0`, `1`, `true`, `false` (case-insensitive) on conversion; anything else is a failure.
- Dates stay ISO text (`VARCHAR(32)` on MySQL), never `DATETIME`.
- Strings get `VARCHAR(n)` only when the field is unique or indexed (`n` = field `length`, default 191) and validation then limits them to `n` characters; all other text stays `LONGTEXT`/`TEXT`.
- Unique and indexed columns compare exactly on MySQL (`utf8mb4_bin`). Empty values (NULL, `''`) are never unique-checked.
- `__revisions` tables stay text and are never checked; `__published` tables are typed like their working table but carry no unique indexes.
- Backups go to the site's `storage/backups/` with a timestamp; no rotation.
- Long SSH jobs are killed after about 12 minutes: the conversion works table by table and a rerun continues.
- Message keys in the `validation` group, en/de/fr (German "Sie" in public texts; the validation group is public).
- The full suite stays green (baseline from `main` before Task 1); PHPUnit deprecations not above the baseline count.

## Review Focus

1. **A partial save on SQLite** (a caller passing only some columns) used to null every other column through `INSERT OR REPLACE`; after Task 1 it must leave them alone. Test in Task 1.
2. **A save colliding with another record's unique value on MySQL** must fail with a field error and leave *both* rows untouched (today's `ON DUPLICATE KEY UPDATE` would update the other row once a unique index exists). Test in Task 4 (MySQL group).
3. **A conversion interrupted after some tables** (process killed): the rerun continues and does not re-convert typed columns. Test in Task 5.
4. **A number stored as `'12'` on SQLite** must read back `'12'`/`12`, not `'12.0'` (SQLite `REAL` affinity would change it); use `NUMERIC`. Test in Task 3.
5. **A Folio form save with a duplicate slug** shows "already taken" on the field, not a 500 (both the validation path and the race path where the database refuses). Test in Task 4.

---

### Task 1: Clearing and a primary-key-only upsert

**Files:**
- Modify: `packages/data/src/Driver/PdoDriver.php` (`save()`, `insertNew()`), `packages/data/src/Driver/SqliteDialect.php`, `packages/data/src/Driver/MysqlDialect.php`, `packages/data/src/Driver/Dialect.php`, `packages/data/src/Driver/JsonFileDriver.php` (`save()`)
- Test: `packages/data/tests/Driver/ClearingTest.php` (SQLite + JSON), `packages/data/tests/Driver/MysqlClearingTest.php` (`#[Group('mysql')]`)

**Interfaces:**
- Produces: `PdoDriver::save()` writes null as `NULL`, never deletes or replaces a row, and never updates a row other than the one with `$id`. `Dialect::upsertSql()` is replaced by `Dialect::updateSql(string $table, array $columns, string $idField): string` (UPDATE … WHERE id = ?) plus the existing `insertSql()`; SQLite may instead use `INSERT … ON CONFLICT(id) DO UPDATE SET …` (conflict target the id only).

- [ ] **Step 1: Write the failing tests**
  - `testNullClearsAColumn` (SQLite, MySQL, JSON): save `{title: 'A', subtitle: 'B'}`, save `{title: 'A', subtitle: null}`, read back: `subtitle` is null.
  - `testPartialSaveKeepsOtherColumns` (SQLite, MySQL): save `{title: 'A', subtitle: 'B'}`, save `{title: 'C'}` only, read back: `subtitle` is still `'B'`.
  - `testInsertWritesNullColumns`: a first save with a null field stores NULL (no error).
  - `testSaveNeverTouchesAnotherRow` (MySQL): table with an extra `UNIQUE` index on `slug` created in the test; rows `a` (slug `x`) and `b` (slug `y`); saving `b` with slug `x` throws a `\PDOException` with SQLSTATE `23000`, and rows `a` and `b` are unchanged.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/data/tests/Driver/ClearingTest.php` — Expected: FAIL (null kept on MySQL, partial save nulls columns on SQLite).
- [ ] **Step 3: Implement.** MySQL: `UPDATE … WHERE id = ?`; when no row exists (check with `SELECT 1 … WHERE id = ?` if `rowCount()` is 0, as MySQL counts changed rows only), `INSERT`; a primary-key `23000` on that INSERT (a concurrent first save) retries the UPDATE once. SQLite: `ON CONFLICT(id) DO UPDATE`. Remove the null filters in `save()` and `insertNew()`. JSON: a null value is stored as null.
- [ ] **Step 4: Run** the new tests, then `vendor/bin/phpunit packages/data` and `composer test:mysql` — Expected: PASS. Fix any caller that relied on `INSERT OR REPLACE` dropping columns (search the monorepo for tests failing on stale values).
- [ ] **Step 5: Commit** `fix(data): saving null clears a column; the upsert targets the primary key only`

### Task 2: Column kinds and field options

**Files:**
- Create: `packages/data/src/Schema/ColumnKind.php`, `packages/folio/src/Field/HasColumnKind.php`
- Modify: `packages/data/src/FieldKinds.php`, `packages/data/src/TypeFieldDefinition.php`, `packages/data/src/TypeRegistry.php` (`parse()`), `packages/data/src/DataManager.php` (empty → null before writing typed kinds), `packages/data/src/Attributes/Field.php`, `packages/data/src/ModelMetadata.php`, `packages/folio/src/FolioServiceProvider.php` (`registerFieldKinds`), Folio's built-in field types (number, boolean, date, string, select, relation, env, and `Codes/Fields/PurposeFieldType`)
- Test: `packages/data/tests/Schema/ColumnKindTest.php`, `packages/data/tests/TypeRegistryUniqueTest.php`, `packages/folio/tests/Field/ColumnKindsTest.php`

**Interfaces:**
- Produces:
  - `enum ColumnKind: string { case Text = 'text'; case Short = 'short'; case Number = 'number'; case Boolean = 'boolean'; case Date = 'date'; }`
  - `TypeFieldDefinition` gains `public bool|array $unique = false` (`true` or a list of scope field names), `public bool $index = false`, `public ?int $length = null`. Model JSON keys `"unique"`, `"index"`, `"length"`.
  - `#[Field(unique: true|['scope'], index: bool, length: ?int)]`; `ModelMetadata` exposes `uniqueFields(): array<string, list<string>>` (field → scope).
  - `FieldKinds::column(string $fieldType, string $kind): void` registers a type's kind; `FieldKinds::columnKindOf(TypeFieldDefinition $field): ColumnKind` resolves: translatable → `Text`; registered kind; `Short` falls back to `Text` unless the field is unique or indexed; unknown types → `Text`.
  - `interface HasColumnKind { public function columnKind(array $config): ColumnKind; }` — Folio's provider registers every field type implementing it. Mapping: number → `Number`; boolean → `Boolean`; date → `Date`; string, select, env, purpose, relation without `multiple` → `Short`; everything else `Text`.
  - `TypeDefinition::uniqueFields(): array<string, list<string>>` and `indexedFields(): list<string>`.

- [ ] **Step 1: Write the failing tests**
  - `testDefinitionParsesUniqueIndexAndLength`: `"slug": {"type": "string", "unique": true}`, `"code": {"type": "string", "unique": ["purpose"]}`, `"recipient": {"type": "string", "index": true, "length": 320}` parse into the properties above; `uniqueFields()` is `['slug' => [], 'code' => ['purpose']]`.
  - `testColumnKindOf`: number → `Number`; string not unique → `Text`; string unique → `Short`; translatable string unique → `Text`; relation `multiple: true` → `Text`; unknown type → `Text`.
  - `testEmptyBecomesNullForTypedKinds`: `saveType` with a number field `''` and a boolean field `''` stores NULL; a string field `''` stores `''`.
  - `testUniqueShortFieldGetsLengthRule`: a unique string field without `length` fails validation at 192 characters with `validation.max.string` (`:max` 191).
- [ ] **Step 2: Run** the tests — Expected: FAIL (unknown properties / methods).
- [ ] **Step 3: Implement.** The `max:n` rule is added in `TypeDefinition::validationRules()` for `Short` fields.
- [ ] **Step 4: Run** the tests and `vendor/bin/phpunit packages/data packages/folio` — Expected: PASS.
- [ ] **Step 5: Commit** `feat(data): column kinds, unique and index field options`

### Task 3: Typed record tables, the RecordTables registry and drift

**Files:**
- Create: `packages/data/src/Schema/RecordTables.php`, `packages/data/src/Schema/ColumnSpec.php`
- Modify: `packages/data/src/Schema/RecordTableSync.php`, `packages/data/src/Migration/Schema.php` (column type reading), `packages/data/src/Migration/Grammar/{SchemaGrammar,SqliteGrammar,MysqlGrammar}.php`, `packages/folio/src/Storage/TableSync.php`, `packages/folio/src/Publishing/ShadowTypes.php`, `packages/codes/src/CodeType.php`, `packages/members/src/MemberType.php`, `packages/media/src/MediaServiceProvider.php`, `packages/folio/src/Users/UserPreferences.php`, `packages/folio/src/Translations/StoredOverrides.php`
- Test: `packages/data/tests/Schema/TypedSyncTest.php`, `packages/data/tests/Schema/MysqlTypedSyncTest.php` (`#[Group('mysql')]`), `packages/folio/tests/Storage/TableSyncTypedTest.php`

**Interfaces:**
- Consumes: `ColumnKind`, `FieldKinds::columnKindOf()`, `TypeDefinition::uniqueFields()`/`indexedFields()` (Task 2).
- Produces:
  - `final readonly class ColumnSpec { string $name; ColumnKind $kind; ?int $length; }`
  - SQL per kind — MySQL: Text `LONGTEXT`, Short `VARCHAR(n) COLLATE utf8mb4_bin`, Number `DOUBLE`, Boolean `TINYINT(1)`, Date `VARCHAR(32)`; SQLite: Text `TEXT`, Short `TEXT`, Number `NUMERIC`, Boolean `INTEGER`, Date `TEXT`. One method `SchemaGrammar::recordColumnType(ColumnKind, ?int $length): string`.
  - Index names: `uniq_{table}_{field}` / `idx_{table}_{field}`; when longer than 64 characters, `uniq_` + first 50 characters + `_` + 8 hex of `crc32` of the full name (one helper `SchemaGrammar::indexName(string $prefix, string $table, string $field): string`).
  - `RecordTableSync::sync(TypeDefinition $def, array $extraColumns = [], bool $typed = true, bool $unique = true): list<string>` — creates typed tables, adds missing columns typed, creates unique (field + scope) and plain indexes **only for tables and columns it creates in this call**; for existing columns whose SQL type differs from the expected kind it returns a line `"warning: {table}.{column} is {actual}, expected {kind}; convert the table (see the data README)"` and changes nothing.
  - `RecordTables` (container singleton in data): `add(string $source, \Closure $definitions): void` where the closure returns `list<array{def: TypeDefinition, extra: list<string>, typed: bool, unique: bool}>`; `all(): list<…>` (same shape). Every caller of `RecordTableSync` registers its tables there: Folio (working tables typed+unique, `__published` typed without unique, `__revisions` `typed: false`), codes, members, media assets, user preferences, stored overrides.
  - `Schema::columnTypes(string $table): array<string, string>` (lower-case SQL type per column).

- [ ] **Step 1: Write the failing tests**
  - `testNewTableIsTyped` (SQLite, MySQL): a definition with number, boolean, date, unique string, plain string fields creates the column types listed above; a unique index exists on the unique field.
  - `testNumberKeepsIntegerText` (SQLite): store `'12'` in a `Number` column, read back `'12'` or `12` (never `'12.0'`); `'12.5'` reads back numerically equal 12.5. Numbers sort numerically (`9` before `10`).
  - `testExistingColumnIsNeverAltered`: a table created untyped (all TEXT) keeps its types after `sync()`; `sync()` returns the warning lines; no index added.
  - `testNewColumnOnExistingTableIsTyped`: a field added to the definition is added with its kind (and its unique index).
  - `testRevisionsStayText` (Folio): a publishing type's `__revisions` table has only text columns; `__published` is typed and has no unique index.
  - `testRegistryListsEveryRecordTable`: with Folio, codes, members, media booted in the test app, `RecordTables::all()` includes the `code`, `member`, asset, preferences and overrides tables and each Folio type with its shadows.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit` (full) and `composer test:mysql` — Expected: PASS.
- [ ] **Step 5: Commit** `feat(data): typed record tables, RecordTables registry, drift warning`

### Task 4: Unique fields: validation, database errors, scopes, Folio, codes, members

**Files:**
- Create: `packages/data/src/Validation/Unique.php`, `packages/data/src/UniqueViolation.php`
- Modify: `packages/data/src/DataManager.php`, `packages/data/src/Driver/PdoDriver.php` (unique `23000` → `UniqueViolation` naming the index), `packages/data/src/Driver/JsonFileDriver.php`, `packages/validation/lang/{en,de,fr}/validation.php`, `packages/folio/src/Content/RecordValidator.php`, `packages/folio/src/Http/AdminController.php` (only if the form path doesn't already turn a `ValidationException` from the save into field errors), `packages/codes/models/code.json`, `packages/codes/src/Codes.php` (`issue()` retries a duplicate random code), `packages/members/models/member.json` (or wherever the member definition lives)
- Test: `packages/data/tests/UniqueTest.php`, `packages/data/tests/MysqlUniqueTest.php` (`#[Group('mysql')]`), `packages/folio/tests/Content/UniqueFieldFormTest.php`, `packages/codes/tests/CodeUniqueTest.php`

**Interfaces:**
- Consumes: `TypeDefinition::uniqueFields()`, `ModelMetadata::uniqueFields()` (Task 2), unique indexes (Task 3).
- Produces:
  - `final class Unique implements ValidationRule` — `new Unique(DataManager $dm, TypeDefinition|string $type, string $field, array $scope = [], string|int|null $exceptId = null, ?string $locale = null)`; returns `new Message('validation.unique')` on a duplicate; empty values pass.
  - Message key `validation.unique`: en "This value is already taken.", de "Dieser Wert ist bereits vergeben.", fr "Cette valeur est déjà utilisée."
  - `DataManager::saveType()`, `saveTypeIfVersion()`, `save()`, `insert()`, `update()` add `Unique` for every unique field when validating, and **always** (validated or not) turn a `UniqueViolation` from the driver into `ValidationException` with `validation.unique` on the field the index belongs to.
  - Translatable unique fields: checked per locale inside the save; simultaneous saves serialised with a lock (MySQL `GET_LOCK('preflow_unique_{table}', 10)` around check+write; SQLite `BEGIN IMMEDIATE`); JSON under the driver's type-wide `flock`.
  - Codes: `code` is `"unique": ["purpose"]`, `recipient` is `"index": true, "length": 320`. Members: `email` is `"unique": true, "length": 320` (my ruling; duplicate members break sign-in).

- [ ] **Step 1: Write the failing tests**
  - `testDuplicateIsAFieldError` (SQLite, MySQL, JSON): two records with slug `x`: the second `saveType()` throws `ValidationException` whose errors have `slug` → "This value is already taken."
  - `testOwnValueIsNotADuplicate`: re-saving a record with its own slug passes.
  - `testEmptyIsNeverDuplicate`: two records with slug `''` and two with null both save.
  - `testScopedUnique`: codes `ABC` in purposes `p1` and `p2` both save; a second `ABC` in `p1` fails.
  - `testRaceIsAFieldErrorAndTouchesNothing` (MySQL; Review Focus 2): save with `validate: false` a record whose slug collides: `ValidationException` on `slug`; both rows unchanged.
  - `testTranslatableUniquePerLocale`: slugs `{de: 'spiel', en: 'game'}` and `{de: 'spiel2', en: 'game'}` → the second fails on `slug.en`.
  - `testExactComparison` (MySQL): `cafe` and `café` both save; `Cafe` and `cafe` both save.
  - `testFolioFormShowsAlreadyTaken` (Review Focus 5): posting Folio's edit form for a type with a unique slug and a taken value re-renders the form with the message on the field and status 422 (or whatever the form uses for validation errors today), no 500; the same when the save path's database refuses (simulate by saving the duplicate between validation and write through a test hook, or with validation disabled on the record).
  - `testIssueRetriesDuplicateCode` (codes): with a code generator stubbed to return an existing code once, `issue()` returns a different code.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** full `vendor/bin/phpunit` and `composer test:mysql` — Expected: PASS.
- [ ] **Step 5: Commit** `feat(data): unique fields with validation and a database guard`

### Task 5: The one-time conversion script (owner, 2026-10-27: not a framework command)

**Files:**
- Create: `tools/convert-typed-columns.php` (monorepo root; not in a package), `tools/tests/ConvertTypedColumnsTest.php` (run by the root phpunit config; add the directory there if needed)

**Interfaces:**
- Consumes: `RecordTables::all()`, `FieldKinds::columnKindOf()`, `SchemaGrammar::recordColumnType()`/`indexName()`, `Schema::columnTypes()` (Tasks 2–3).
- Produces: `php tools/convert-typed-columns.php <site-dir> [--check] [--storage=<driver>]`. The logic lives in classes inside the script's own namespace `Preflow\Tools\Convert` (in `tools/Convert/*.php`, autoloaded by the script itself), so the test can drive it against a test app: `Checker::check(): list<Failure>`, `Backup::create(): string`, `Converter::convert(\Closure $log): void`. Exit codes: 0 clean/converted/nothing to do, 1 failures or backup failure.
  - Accepted values: Number — `''`/NULL or `^-?\d+(\.\d+)?$` (a comma is a failure); Boolean — `''`, `0`, `1`, `true`, `false` case-insensitive; Date — `''`/NULL or one of `Y-m-d`, `Y-m-d\TH:i`, `Y-m-d\TH:i:s`, `Y-m-d\TH:i:sP`, `Y-m-d\TH:i:s.uP`, `Y-m-d\TH:iP`, `Y-m-d H:i:s`; Short — length ≤ n; unique — no two non-empty equal values within the scope (exact).
  - Tables with `typed: false` (`__revisions`) are skipped. Already typed columns are skipped.
  - Backup: SQLite `VACUUM INTO`; MySQL `mysqldump` with a temporary 0600 option file (never credentials on the command line); into `<site-dir>/storage/backups/`. No rotation.

- [ ] **Step 1: Write the failing tests** (SQLite; MySQL variants in `#[Group('mysql')]`)
  - `testCheckListsEveryBadValue`: number `'12 Spieler'`, boolean `'ja'`, date `'27.10.2026'`, a unique duplicate → four failures with table, id, field, value; database unchanged, no backup written.
  - `testConvertTypesAndKeepsValues`: `'12'`, `'12.5'`, `''` numbers → 12, 12.5, NULL; booleans `'1'`/`''`/`'true'` → 1, NULL, 1; dates unchanged; numeric order correct; a backup file exists.
  - `testSecondRunNothingToConvert`.
  - `testRevisionsUntouched`: a `__revisions` table with `'12 Spieler'` neither fails nor changes.
  - `testInterruptedRunContinues`: a `$log` that throws after the first table; the rerun converts the rest, typed tables skipped.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the tests, `vendor/bin/phpunit` and `composer test:mysql` — Expected: PASS.
- [ ] **Step 5: Dry runs on copies.** Copy smart-stack.works' and smeyer.me's local databases into the scratch directory, point the sites at the copies (never the originals; never production), run `--check` then the conversion, and record the output in the release checklist.
- [ ] **Step 6: Commit** `feat(tools): one-time script converting record tables to typed columns`

### Task 6: Docs, upgrade notes, release checklist, site dry runs

**Files:**
- Modify: `packages/data/README.md` (clearing, unique, index, length, column kinds, `RecordTables`, the drift warning), `packages/folio/README.md` (unique fields in model JSON, field types' `HasColumnKind`), `packages/codes/README.md`, `packages/members/README.md`, `ROADMAP.md` (step 3 done; roadmap entries struck; the codes "Index on (recipient, purpose)" item done)
- Create: `docs/superpowers/plans/2026-10-27-release-0.42-checklist.md`

- [ ] **Step 1:** Write the READMEs and the checklist. The checklist's upgrade notes: partial saves no longer null other columns on SQLite; null clears on MySQL; members `email` and codes `code` unique (a site with duplicates sees them in the script's `--check`); the order per site: `tools/convert-typed-columns.php --check` on a local copy, deploy (migrate stays additive), then the script on the live site with the owner's go-ahead; restore steps for the backup (SQLite: copy the file back; MySQL: `mysql < dump`); holz-objekte.org converts in its own session.
- [ ] **Step 2: Site suites.** Run both sites' suites against the framework branch (`vendor/preflow/*` symlinks; delete `storage/cache/folio-*.cache` first): green.
- [ ] **Step 3: Run** full `vendor/bin/phpunit` and `composer test:mysql`; record counts in the checklist.
- [ ] **Step 4: Commit** `docs: data integrity (clearing, unique fields, typed columns, conversion script)`
