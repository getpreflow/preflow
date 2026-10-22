# Members Groundwork A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** This plan provides:
- Folio types stored in SQLite (tables from model JSON, a real migrate step);
- user types that stay on the server;
- a generic throttle;
- the Folio extension core: discovery, the committed file, screens, type customizations,
  permissions, the Extensions screen and CLI;
- live extension settings.

**Architecture:**
- **Data layer:** gets loud storage resolution, schema introspection and a migration
  registry that packages add paths and post-migrate steps to. The devtools `migrate`
  command boots the app and runs it.
- **Folio:**
  - It syncs SQL tables from its type definitions as such a step.
  - It learns runtime-defined types, which extension models and settings use.
  - It discovers extensions under `app/Folio/*` and from Composer `extra.folio.extension`.
  - It wires their parts into the routes, the sidebar, the list, the form and the save
    flow.
- **Throttle:** a small service in `preflow/core` that stores through the DataManager.

**Tech Stack:** PHP 8.4, PHPUnit 11, Twig, SQLite via PDO, vanilla JS (no build step).

**Spec:**
- `docs/superpowers/specs/2026-10-22-members-groundwork-design.md`: sections 1, 2, 2b and 5.
- Through its section 1, also `docs/superpowers/specs/2026-10-21-folio-extensions-design.md`:
  levels 2–3, opt-in and the committed file, live settings, the calls after the builder
  check.

Plan B (codes, RBAC, smart-stack.works adoption, release 0.20) is written after this one
lands, against the real interfaces.

## Deferred from the extensions spec (plan calls, for review)

- **`#[ListAction]`:** acting on selected records needs a selection UI that the list
  doesn't have. Record actions cover the members approval queue.
- **Screen `badge`** ("3 waiting").
- **`folio:extensions:remove`:** removing an extension's data stays manual until an
  extension needs it.
- **Building-block extraction** for the list and edit screens. Screens extend the
  existing layout and use its classes.
- **Hooks don't run** on `reorder()` or on `PublishingController` restore, discard or
  autosave (Task 11).
- **Settings permissions:** they are the settings type's standard
  `{name}_settings.view|edit`, not `{extension}.settings` (Task 13).

## Global Constraints

- **Code:** PHP 8.4, `declare(strict_types=1)`, final classes where the codebase uses them.
  Strict nonce CSP: no inline handlers or styles, and inline scripts carry
  `nonce="{{ csp_nonce() }}"`. No build step, vanilla JS.
- **Folio admin routes** live under the Folio prefix (`config/folio.php` `path`, default
  `/folio`). Only there do session, login and CSRF exist.
- **Loud failures:** a misnamed class, a missing template, a duplicate route or path, or
  an unknown storage is an error on boot in debug and in `folio:extensions:check`. It is
  never a silent skip.
- **Permissions:** undeclared ones throw in debug. Those of disabled extensions or parts
  are denied and logged once, never a 500.
- **No regressions:** a site without `config/folio/extensions.json` and without
  `app/Folio/*/Extension.php` behaves exactly as today (smeyer.me, preflow.dev,
  smart-stack.works). That includes the sidebar markup order: Dashboard, Content,
  Library.
- **Data stays on the server:**
  - User types (`"users": "<population>"`) are not transferred unless `"transfer": true`.
  - Per-environment state (extension installed versions, throttle rows) is never
    exported.
- **Secrets** are never stored in settings. Env fields store nothing and show only "set"
  or "not set".
- **Tests:** run from the repo root.
  - One suite: `vendor/bin/phpunit --testsuite <Data|Core|DevTools|Folio|Auth>`.
  - Everything: `vendor/bin/phpunit`.
  - The full suite is green at the end of every task.
- **Branch and commits:** branch `feat/members-groundwork-a` off `main`. Conventional
  commit subjects with the package scope (`feat(folio): …`).

## Review Focus

1. **Existing sites upgrade unchanged.** No extensions file and no extension folders mean
   the same routes, sidebar and behaviour. The test is in Task 9
   (`test_no_extensions_keeps_todays_sidebar`).
2. **A record key with no column** (a model JSON field added without running migrate)
   must fail with a message naming `preflow migrate`, not a raw PDO error. The test is in
   Task 4 (`test_unknown_column_names_migrate`).
3. **A screen path that collides** with a type key or a Folio path (`login`, `logout`,
   `_assets`, `_uploads`, `media`, `extensions`) is a boot error. The test is in Task 10
   (`test_screen_path_colliding_with_type_throws`).
4. **Settings before first save** return the declared defaults. An env field never
   stores a posted value. The tests are in Task 13 (`test_defaults_before_first_save`,
   `test_env_field_never_stored`).
5. **Throttle without `REMOTE_ADDR`** (CLI, tests, odd proxies) uses one fixed `unknown`
   client and never throws. The test is in Task 6 (`test_missing_remote_addr_is_unknown_client`).

---

### Task 1: Unknown storage is an error

**Files:**
- Modify: `packages/data/src/DataManager.php:355-371` (`driver()`, `resolveDriver()`)
- Test: `packages/data/tests/DataManagerDynamicTest.php`

**Interfaces:**
- Produces: `DataManager::driver(string $name): StorageDriver`. It throws
  `\RuntimeException("Storage driver [{$name}] is not configured (config/data.php drivers).")`
  when `$name` is neither a configured driver nor `'default'`. There is no fallback to
  the default any more.

- [ ] **Step 1: Write the failing tests**

```php
public function test_type_with_unknown_storage_throws(): void
{
    // TypeDefinition with storage 'nope', DataManager with only ['default' => json]
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Storage driver [nope] is not configured');
    $this->dm->queryIn($defWithStorageNope)->get();
}

public function test_default_storage_name_still_resolves(): void
{
    // a TypeDefinition with storage 'default' saves and finds a record
}
```

- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Data --filter 'unknown_storage|default_storage_name'`.
  Expected: the first test FAILS (no exception, because of the silent fallback).
- [ ] **Step 3: Implement.** Remove the fallback branch and update the docblock of
  `driver()`.
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS. If a test
  relied on the fallback (for example a model with `"storage":"json"` while only
  `default` is configured), fix the test's driver map, not the code.
- [ ] **Step 5: Commit.** `fix(data): an unknown storage name is an error, not a silent fallback to the default`

### Task 2: Schema introspection, several migration paths, the migration registry

**Files:**
- Modify: `packages/data/src/Migration/Schema.php`, `Table.php`, `Migrator.php`
- Create: `packages/data/src/Migration/MigrationRegistry.php`
- Modify: `packages/core/src/Application.php` (`bootDataLayer()`: bind the registry as a
  singleton and add `basePath('migrations')`)
- Test: `packages/data/tests/Migration/SchemaTest.php`, `MigratorTest.php`,
  `MigrationRegistryTest.php`

**Interfaces:**
- **Produces, on `Schema`:**
  - `Schema::hasTable(string $table): bool`
  - `Schema::columns(string $table): list<string>`, from `PRAGMA table_info`
  - `Schema::addColumn(string $table, string $column, string $type = 'TEXT'): void`,
    nullable, via `ALTER TABLE … ADD COLUMN`
- **Produces, on `Migrator`:**
  - `new Migrator(\PDO $pdo, string|array $migrationsPaths)`. Each path is globbed, and
    all files are sorted by basename across paths.
  - A duplicate basename in two paths throws `\RuntimeException` naming both files.
- **Produces, `MigrationRegistry`:**
  - `addPath(string $dir): void` (missing dirs are ignored)
  - `paths(): list<string>`
  - `afterMigrate(string $name, \Closure $step): void`. The step has the signature
    `\Closure(): list<string>` and returns the changes made, one line each.
  - `steps(): array<string, \Closure>`
  - A duplicate step name throws.
- The SQL dialect stays SQLite-only for `hasTable`, `columns` and `addColumn`. Calling
  them on a non-SQLite PDO throws `\LogicException('… supports SQLite only for now')`.

- [ ] **Step 1: Write the failing tests:**
  - `test_has_table_and_columns`: after `create('t', …uuid id, string name)`,
    `hasTable('t')` is true, `hasTable('x')` is false, and `columns('t') === ['id','name']`.
  - `test_add_column_is_nullable`
  - `test_migrates_files_from_two_paths_in_name_order`
  - `test_duplicate_migration_name_across_paths_throws`
  - `test_registry_ignores_missing_dir_and_rejects_duplicate_step`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Data --filter 'Schema|Migrator|MigrationRegistry'`.
  Expected: FAIL (undefined methods or class).
- [ ] **Step 3: Implement** the methods and the class above, and bind the registry in
  `bootDataLayer()`.
- [ ] **Step 4: Run them again.** Run `vendor/bin/phpunit --testsuite Data` and
  `--testsuite Core`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(data): schema introspection, migrations from several paths, a migration registry for packages`

### Task 3: `preflow migrate` boots the app and runs the registry

**Files:**
- Modify: `packages/devtools/src/Command/MigrateCommand.php`
- Test: `packages/devtools/tests/MigrateCommandTest.php` (new)

**Interfaces:**
- Consumes: `MigrationRegistry` from Task 2, and `Application::create($cwd)->boot()`.
- Produces:
  - `MigrateCommand` (name `migrate`) takes an optional constructor argument
    `?\Closure $appFactory` (same pattern as Folio's `BootsApplication`).
  - It runs `Migrator` over `MigrationRegistry::paths()` on the PDO bound in the container
    (`\PDO::class`, bound by `createSqliteDriver`). Then it runs every `steps()` closure
    and prints each change line.
  - Options: `--storage=<driver key>` (default: the `sqlite` driver; error if there is no
    SQLite driver) and `--pretend` (lists pending migrations, runs no steps).
  - Exit code 0 when nothing is pending, 0 after success, and 1 with the message on any
    exception.

- [ ] **Step 1: Write the failing tests** (temporary app dir with a SQLite driver and two
  migration dirs, one registered through the registry by a test provider):
  - `test_runs_app_and_package_migrations`
  - `test_runs_after_migrate_steps_and_prints_changes`
  - `test_pretend_runs_nothing`
  - `test_without_sqlite_driver_exits_1`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite DevTools --filter MigrateCommand`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them again.** Run `vendor/bin/phpunit --testsuite DevTools`.
  Expected: PASS.
- [ ] **Step 5: Commit.** `feat(devtools): migrate boots the app, runs package migrations and post-migrate steps`

### Task 4: Folio tables from model JSON, Folio proven on SQLite

**Files:**
- Modify: `packages/data/src/Driver/PdoDriver.php` (add `pdo(): \PDO`; wrap an unknown
  column error, see below)
- Create: `packages/folio/src/Storage/TableSync.php`
- Modify: `packages/folio/src/FolioServiceProvider.php` (register the step with the
  `MigrationRegistry` as `folio.tables`)
- Test:
  - `packages/folio/tests/Storage/TableSyncTest.php` (new)
  - `packages/folio/tests/Storage/SqliteContentTest.php` (new)

**Interfaces:**
- **Produces:**
  - `TableSync::__construct(TypeCatalog $catalog, TypeRegistry $registry, DataManager $dm)`
  - `TableSync::sync(): list<string>`
- **What `sync()` covers:** every catalog type whose driver is a `PdoDriver`.
  - The type's table, plus `{table}__published` and `{table}__revisions` when the listing
    is `publishing`, taken from `ShadowTypes`.
  - The asset table when `folio.assets.storage` names a PDO driver.
- **Columns:**
  - The id field is `TEXT PRIMARY KEY`.
  - Every field and every system column is nullable `TEXT`.
  - System columns: `_locales`, `_order`, and the keys of `Publisher::META`.
- **Behaviour:**
  - It creates missing tables and adds missing columns. It never drops or alters.
  - It returns lines like `created table posts` and `added column posts.subtitle`.
  - On a MySQL driver it throws `\LogicException('Folio table sync supports SQLite only for now')`.
- **Unknown column errors:** a PDO "no such column" or "has no column named" error during
  `save` is rethrown as `\RuntimeException("Table {table} has no column {col}; run `preflow migrate`.")`.

- [ ] **Step 1: Write the failing TableSync tests:**
  - `test_creates_type_and_shadow_tables`
  - `test_adds_new_field_column_and_keeps_data`
  - `test_never_drops_removed_field_column`
  - `test_skips_json_stored_types`
  - `test_unknown_column_names_migrate`
- [ ] **Step 2: Write the failing SQLite content tests.** `SqliteContentTest` sets up a
  `post` type with `"storage":"sqlite"` and locales en/de, runs `sync()`, then exercises
  the real services:
  - `test_collection_list_sorted_and_localized` (Collections, ListOrder)
  - `test_publish_then_live_reads_published_copy` (Publisher, LiveContent)
  - `test_frontend_resolver_finds_by_localized_slug`
  - `test_singleton_roundtrip` (Singletons)
  - `test_array_field_roundtrips` (a relation or blocks field reads back as an array
    through the field type's `fromStorage`)
  - `test_export_import_roundtrip_on_sqlite` (Exporter and Importer between two SQLite
    files)
- [ ] **Step 3: Run them.** Run `vendor/bin/phpunit --testsuite Folio --filter 'TableSync|SqliteContent'`.
  Expected: FAIL.
- [ ] **Step 4: Implement** `TableSync`, `PdoDriver::pdo()`, the error wrap and the step
  registration.
- [ ] **Step 5: Run them again and fix what SQLite reveals.** Run the same filter. Every
  remaining failure is a JSON-only assumption in Folio or `PdoDriver`. Fix it at its
  source and give each fix a line in the ledger. Likely spots are JSON columns read back
  as strings, null handling with `INSERT OR REPLACE`, and string versus int ids.
  Expected: PASS.
- [ ] **Step 6: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 7: Commit.** `feat(folio): SQL-stored types get their tables from model JSON; Folio content proven on SQLite`

### Task 5: User types and the transfer switch

**Files:**
- Modify: `packages/folio/src/Content/TypeListing.php`, `TypeCatalog.php`
- Modify: `packages/folio/src/Transfer/ContentSet.php:34-42`
- Modify: `packages/folio/src/Transfer/Importer.php:108-111` (message)
- Test:
  - `packages/folio/tests/Content/TypeCatalogTest.php`
  - `packages/folio/tests/Transfer/ExportImportTest.php`

**Interfaces:**
- **Produces, on `TypeListing`:**
  - New properties `?string $users = null` and `bool $transfer = true`.
  - When the model JSON says `"users": "<population>"`, `transfer` defaults to `false`.
  - An explicit `"transfer"` bool always wins.
- **TypeCatalog validation** throws `FrontendConfigException` in these cases:
  - `users` is set but is not a non-empty string;
  - a user type has `frontend`;
  - a user type is a `singleton`.
- **`ContentSet::fromApp`** skips listings with `transfer === false`, together with their
  shadows.
- **Import:** an archive table that is not in the set fails with
  `"{table} is not transferred (user data or not a Folio type)"`.

- [ ] **Step 1: Write the failing tests:**
  - `test_user_type_defaults_to_no_transfer`
  - `test_user_type_can_opt_in`
  - `test_non_user_type_can_opt_out`
  - `test_user_type_with_frontend_throws`
  - `test_export_leaves_out_user_type`
  - `test_import_refuses_user_type_table`
  - `test_backup_leaves_out_user_type`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Folio --filter 'TypeCatalog|ExportImport'`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them again.** Run `vendor/bin/phpunit --testsuite Folio`.
  Expected: PASS.
- [ ] **Step 5: Commit.** `feat(folio): user types ("users": population) stay on the server; per-type transfer switch`

### Task 6: Throttle

**Files:**
- Create: `packages/core/src/Throttle/Throttle.php`, `packages/core/migrations/2026_10_22_000000_preflow_throttle.php`
- Modify: `packages/core/src/Application.php`
  - Bind `Throttle` as a singleton.
  - Add `packages/core/migrations` to the `MigrationRegistry`.
- Test: `packages/core/tests/Throttle/ThrottleTest.php`

**Interfaces:**
- **Produces:**
  - `Throttle::__construct(DataManager $dm, string $key, string $storage = 'default', ?\Closure $clock = null)`.
    `$key` is `APP_KEY` from `app.key`.
  - `Throttle::client(ServerRequestInterface $request): string`
  - `Throttle::tooMany(string $purpose, string $client, int $max, int $windowSeconds): bool`
  - `Throttle::hit(string $purpose, string $client, int $windowSeconds): void`
  - `Throttle::clear(string $purpose, string $client): void`
- **`client()`:**
  - It returns `hash_hmac('sha256', $ip, $key . '|' . gmdate('Y-m-d'))`.
  - `$ip` is the `REMOTE_ADDR` server param only (no forwarded headers), or the literal
    `'unknown'`.
- **Storage:**
  - Fixed window, one row per `sha256(purpose|client)` with fields `purpose`, `hits` and
    `window_start`, in table `preflow_throttle`.
  - Expired rows are overwritten on the next hit.
- **The migration** creates `preflow_throttle(id TEXT PK, purpose TEXT, hits INTEGER, window_start INTEGER)`.
- **On a JSON default driver** the same rows are JSON files and the migration is not
  needed.

- [ ] **Step 1: Write the failing tests** (clock injected):
  - `test_allows_until_max_then_blocks`
  - `test_window_expiry_resets`
  - `test_clear_resets`
  - `test_purposes_are_separate`
  - `test_client_hash_has_no_raw_ip` (the IP string does not appear in the result)
  - `test_missing_remote_addr_is_unknown_client`
  - `test_works_on_sqlite_and_json`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Core --filter Throttle`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them again.** Run `vendor/bin/phpunit --testsuite Core`.
  Expected: PASS.
- [ ] **Step 5: Commit.** `feat(core): Throttle, fixed-window attempt limits with a salted, daily-rotating client hash`

### Task 7: Types defined at runtime

**Files:**
- Modify: `packages/data/src/TypeRegistry.php`
- Modify: `packages/folio/src/Content/TypeCatalog.php`
- Test:
  - `packages/data/tests/TypeRegistryTest.php`
  - `packages/folio/tests/Content/TypeCatalogTest.php`

**Interfaces:**
- **Produces, on `TypeRegistry`:**
  - `TypeRegistry::define(array $schema): TypeDefinition`. The schema is the same array
    shape as a model JSON file, and goes through the same parsing as file loading.
  - It throws when the key already exists as a file or as a definition.
  - `has()` and `get()` see defined types.
- **Produces, on `TypeCatalog`:**
  - `TypeCatalog::add(array $schema, string $section = 'content'): void` adds a listing
    built from the same keys as `scan()`.
  - It throws on a duplicate key.
  - `TypeListing` gains `string $section = 'content'` (values `content` or `settings`).
- **Ordering:** the catalog lists file types first, then added types in insertion order.

- [ ] **Step 1: Write the failing tests:**
  - `test_define_then_get`
  - `test_define_duplicate_of_file_throws`
  - `test_catalog_add_appears_after_files`
  - `test_catalog_add_duplicate_throws`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --filter 'TypeRegistryTest|TypeCatalogTest'`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(data,folio): types can be defined at runtime (extension models and settings)`

### Task 8: Extension discovery, the committed file, enabled state

**Files:**
- Create in `packages/folio/src/Extension/`:
  - `Attributes/FolioExtension.php`
  - `Extension.php`
  - `ExtensionDefinition.php`
  - `ExtensionRegistry.php`
  - `ExtensionsFile.php`
  - `ExtensionException.php`
- Modify: `packages/folio/src/FolioServiceProvider.php`. In `register()`, build the
  registry before TypeCatalog consumers resolve. In `boot()`, wire the enabled parts.
- Test:
  - `packages/folio/tests/Extension/DiscoveryTest.php`
  - `ExtensionsFileTest.php`
  - fixtures under `packages/folio/tests/Fixtures/extensions/`

**Interfaces:**
- **`#[FolioExtension(name, label, description = '', icon = 'ui-puzzle', version = '1.0.0')]`**
  - It is a class attribute on a class extending the abstract `Extension`.
  - `Extension` has the no-op methods `install(Container $c): void` and
    `uninstall(Container $c): void`.
  - `name` matches `/^[a-z][a-z0-9_]*$/`.
- **Where extensions come from:**
  - **Project:** each `app/Folio/{Dir}/Extension.php` is class `App\Folio\{Dir}\Extension`.
    `App\Folio\Overrides` is skipped.
  - **Packages:** `vendor/composer/installed.json` entries with `extra.folio.extension` (an
    FQCN).
- **The folder:** an extension's folder is the directory of its `Extension.php`. Parts
  are discovered by scanning PHP files in it recursively, with the class name derived
  from the extension's namespace plus the relative path. Models come from `{folder}/models/*.json`
  and templates from `{folder}/templates/` (Twig namespace = extension `name`).
- **`ExtensionDefinition` (readonly):** `name, label, description, icon, version, class,
  folder, parts`.
  - `parts` is `list<array{id: string, kind: string, class?: string, label: string}>`.
  - Part ids: `model:{key}`, `screen:{path}`, `type:{key}` (customization) and `settings`.
- **`ExtensionsFile`** reads and writes `config/folio/extensions.json`:
  `{"enabled": {"<name>": {"disabled_parts": ["screen:x"]}}}`.
  - An extension that is not listed is off. A missing file means everything is off.
  - It writes with pretty print, sorted keys and a trailing newline.
- **`ExtensionRegistry`:**
  - `all(): list<ExtensionDefinition>`
  - `enabled(): list<ExtensionDefinition>`
  - `isEnabled(string $name): bool`
  - `partEnabled(string $name, string $partId): bool`
  - `get(string $name): ExtensionDefinition` (throws when unknown)
- **Loud errors (`ExtensionException`) when:**
  - an `Extension.php` has no attribute or a wrong class name;
  - two extensions have the same name;
  - the file enables an unknown extension or part.
  In production (not debug) the file errors are logged and the unknown entry is ignored.
  The other errors always throw.
- **Cache:** like `BlockRegistry`, a signature of `class@filemtime` across the discovered
  files plus the mtime of the extensions file, stored at `storage/cache/folio-extensions.cache`
  and used when `!app.debug`.

- [ ] **Step 1: Write the failing tests:**
  - `test_discovers_project_extension_and_parts`
  - `test_discovers_package_extension_from_installed_json`
  - `test_missing_attribute_throws`
  - `test_duplicate_name_throws`
  - `test_unknown_enabled_name_throws_in_debug_logs_in_production`
  - `test_no_file_means_nothing_enabled`
  - `test_disabled_part_reported`
  - `test_file_roundtrip_is_stable` (write then read gives identical bytes)
  - `test_cache_invalidates_on_file_change`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Folio --filter 'Discovery|ExtensionsFile'`.
  Expected: FAIL.
- [ ] **Step 3: Implement.** Extension models of enabled extensions (whose `model:*`
  part is enabled) are registered through `TypeRegistry::define` and `TypeCatalog::add`
  from Task 7.
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(folio): extension discovery (app/Folio/*, composer extra.folio.extension), committed extensions.json`

### Task 9: Sidebar from a nav model

**Files:**
- Create: `packages/folio/src/Http/AdminNav.php`
- Modify:
  - `packages/folio/templates/admin/_layout.twig:41-60`
  - `packages/folio/src/Http/AdminAuthMiddleware.php` (calls
    `AdminNav::setCurrentPath($request->getUri()->getPath())`)
  - `FolioServiceProvider` (the `folio_nav()` Twig function)
- Test:
  - `packages/folio/tests/Http/AdminNavTest.php`
  - an assertion added to `packages/folio/tests/Integration/FolioAppTest.php`

**Interfaces:**
- **Produces:**
  - `AdminNav::add(string $section, string $label, string $url, string $icon, ?string $permission, int $order = 100): void`
  - `AdminNav::sections(): list<array{label: string, items: list<array{label, url, icon, active: bool}>}>`
  - Twig: `folio_nav()` returns `sections()`.
- **Sections and their order:**
  - `dashboard`, which renders without a label;
  - `content` (catalog types with `section === 'content'`);
  - `library` (Assets, then extension items);
  - `settings` (settings types, extension items, and Extensions from Task 14);
  - then extension-defined section names, in the order first used.
- **Filtering:** items whose permission fails `can()` are left out, and empty sections
  are left out.
- **Active item:** the item with the longest URL prefix matching the current path. The
  dashboard is active only on an exact match of `{prefix}`.
- **Items keep today's icons:** `ui-settings` for singletons, `ui-file` for types, and
  Assets as today.

- [ ] **Step 1: Write the failing tests:**
  - `test_no_extensions_keeps_todays_sidebar`: render the layout for a scaffold app. The
    sidebar link labels, hrefs and order are Dashboard, the content types, Assets, the
    same as on `main`.
  - `test_longest_prefix_is_active`
  - `test_items_without_permission_hidden_and_empty_section_dropped`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Folio --filter 'AdminNav|keeps_todays_sidebar'`.
  Expected: FAIL.
- [ ] **Step 3: Implement.** Controllers no longer need to pass `types` for the sidebar;
  keep passing it where templates use it for other things.
- [ ] **Step 4: Run them again.** Run `vendor/bin/phpunit --testsuite Folio`.
  Expected: PASS.
- [ ] **Step 5: Commit.** `refactor(folio): sidebar comes from AdminNav (sections, permission filtering, prefix-based active state)`

### Task 10: Screens (`#[FolioScreen]`)

**Files:**
- Create in `packages/folio/src/Extension/`:
  - `Attributes/FolioScreen.php`
  - `Screen/ScreenView.php`
  - `Screen/ScreenRegistry.php`
- Modify:
  - `packages/folio/src/Http/FolioPermissionMiddleware.php` (consult the `ScreenRegistry`
    for non-Folio handlers, still failing closed)
  - `FolioServiceProvider::boot()` (add screen routes before `FolioRoutes::admin`; nav
    items; the Twig namespace per extension)
- Test: `packages/folio/tests/Extension/ScreenTest.php`, plus a fixture extension with
  one screen

**Interfaces:**
- **`#[FolioScreen(path, label, icon = 'ui-file', section = 'settings', order = 100, permission)]`**
  - It is a class attribute. `path` matches `/^[a-z][a-z0-9-]*(\/[a-z0-9-]+)*$/`, and
    `permission` is required.
  - Methods carry Preflow's `#[Get]` and `#[Post]` attributes, with paths relative to the
    screen (`'/'`, `'/{id}/approve'`).
- **Routes:** each method becomes `{prefix}/{path}{methodPath}` with middleware
  `[AdminAuthMiddleware, FolioPermissionMiddleware]`. The permission middleware asks
  `ScreenRegistry::permissionFor(string $handlerClass): ?string`.
- **What a method returns** (the method receives the request; the class is built by the
  container):
  - `ScreenView` (`new ScreenView(string $template, array $data = [])`). Folio renders it
    with `prefix`, `csrf`, `screen` (label, path) and the data merged in.
  - Or a `ResponseInterface`, or a string.
- **Templates:**
  - Screen templates extend `@folio/admin/_layout.twig`.
  - The extension's templates are reachable as `@{extension name}/…`.
  - A missing template is a `ExtensionException` in debug.
- **Collisions** throw `ExtensionException` on boot. A screen path's first segment may not
  equal:
  - a catalog type key;
  - another screen's path;
  - a reserved segment: `login`, `logout`, `_assets`, `_uploads`, `media`, `extensions`,
    `blocks`, `preview`, `upload`, `assets`.
  Check the reserved list against the full set of segments in `FolioRoutes::admin` and
  extend it to match.

- [ ] **Step 1: Write the failing tests:**
  - `test_screen_renders_in_admin_layout_with_nav_item`
  - `test_screen_post_requires_csrf`
  - `test_screen_without_permission_is_403_and_hidden_from_nav`
  - `test_disabled_screen_part_is_404_and_not_in_nav`
  - `test_screen_path_colliding_with_type_throws`
  - `test_screen_path_colliding_with_reserved_throws`
  - `test_unmapped_non_folio_handler_still_403`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Folio --filter ScreenTest`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them again.** Run `vendor/bin/phpunit --testsuite Folio`.
  Expected: PASS.
- [ ] **Step 5: Commit.** `feat(folio): extension screens via #[FolioScreen], native layout, nav entry, permission, collisions are errors`

### Task 11: Type customizations: panels, record actions, list columns, hooks

**Files:**
- Create in `packages/folio/src/Extension/`:
  - `Attributes/FolioType.php`, `FormPanel.php`, `RecordAction.php`, `ListColumn.php`,
    `BeforeSave.php`, `AfterSave.php`, `BeforeDelete.php`, `AfterDelete.php`
  - `Customization/Customizations.php`
- Modify:
  - `packages/folio/src/Http/AdminController.php`:
    - `list()` 84-149 (extra columns);
    - `form()` 407ff (panels, action buttons);
    - `saveWithBlocks()` 578 and `savePublishing()` 610 (hooks around the save);
    - `destroy()` 383 (delete hooks).
  - `FolioRoutes::admin` (route `POST {prefix}/{type}/{id}/_action/{name}` → `AdminController@recordAction`)
  - `FolioPermissionMiddleware::RULES` (`AdminController@recordAction` → `{type}.edit`).
    The action's own permission is then checked in the controller.
  - `templates/admin/list.twig`, `form.twig`
- Test: `packages/folio/tests/Extension/CustomizationTest.php`

**Interfaces:**
- **`#[FolioType('key')]`** is a class attribute. Its methods are:
  - **`#[FormPanel(label, position = 'after')]`**
    - Signature: `fn(DynamicRecord $record): ScreenView|string`.
    - The result is rendered in `section.folio-form-extras` on the edit form (not on
      create). A string is escaped text; a `ScreenView` is a template.
  - **`#[RecordAction(name, label, permission, icon = null, confirm = null)]`**
    - Signature: `fn(DynamicRecord $record, ServerRequestInterface $request): ?ResponseInterface`.
    - It is rendered as a POST button in the form's `actions` block, with CSRF and
      `data-confirm` when `confirm` is set.
    - `null` means a 302 back to the edit form, with a session flash
      `success: "{label}: done"`.
    - A missing record is a 404. A failing permission is a 403 through `Forbidden::response`.
  - **`#[ListColumn(label, position = 100)]`**
    - Signature: `fn(DynamicRecord $record): string`.
    - The result is escaped text appended after the type's columns, sorted by position.
  - **`#[BeforeSave]`**
    - Signature: `fn(DynamicRecord $record): void`. It may `set()` fields, and it runs
      before validation.
    - It runs in `store()` and `update()` for both publishing and plain types.
    - It does **not** run in `reorder()` or in `PublishingController` restore, discard or
      autosave. This is deliberate and documented in the README.
  - **`#[AfterSave]`:** the same signature; it runs after a successful save.
  - **`#[BeforeDelete]` and `#[AfterDelete]`:**
    - Signature: `fn(DynamicRecord $record): void`.
    - `BeforeDelete` may throw `\Preflow\Folio\Extension\Customization\Refused` with a
      message. That cancels the delete and shows the message as a flash error.
- **Several customizations** of one type (from different extensions) all apply, ordered
  by extension name.
- **Errors:** a `#[FolioType]` for an unknown type key is an `ExtensionException`. So is
  a duplicate action name within one type.
- **`Customizations` service:**
  - `forType(string $key): array{panels, actions, columns, hooks}`
  - `runHooks(string $hook, DynamicRecord $record): void`
  - `action(string $type, string $name): ?array{callable, permission, label}`

- [ ] **Step 1: Write the failing tests:**
  - `test_panel_renders_on_edit_not_on_create`
  - `test_record_action_runs_and_redirects_with_flash`
  - `test_record_action_permission_denied_is_403`
  - `test_record_action_needs_csrf`
  - `test_list_column_appears_escaped`
  - `test_before_save_can_change_record_before_validation`
  - `test_after_save_sees_saved_record`
  - `test_before_delete_refused_keeps_record`
  - `test_hooks_run_for_publishing_type_on_update`
  - `test_customization_for_unknown_type_throws`
  - `test_disabled_type_part_has_no_effect`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Folio --filter CustomizationTest`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them again.** Run `vendor/bin/phpunit --testsuite Folio`.
  Expected: PASS.
- [ ] **Step 5: Commit.** `feat(folio): type customizations — form panels, record actions, list columns, save/delete hooks`

### Task 12: Extension permissions, disabled permissions

**Files:**
- Create: `packages/folio/src/Extension/Attributes/DeclaresPermission.php`
- Modify:
  - `packages/auth/src/Access/PermissionRegistry.php`
  - `packages/auth/src/Access/Access.php:59`
  - `FolioServiceProvider::declarePermissions()`
- Test:
  - `packages/auth/tests/Access/AccessTest.php`
  - `packages/folio/tests/Extension/PermissionsTest.php`

**Interfaces:**
- **`#[DeclaresPermission(name, label)]`**
  - It is repeatable, on the `Extension` class.
  - The group is the extension label.
  - The name must start with `{extension name}.`, otherwise `ExtensionException`.
- **New on `PermissionRegistry`:**
  - `disable(string $name, string $reason): void`
  - `disabledReason(string $name): ?string`
- **`Access::can`:** a disabled name returns `false` and is logged once per name per
  request (`error_log`, "permission {name} belongs to a disabled {reason}"). It never
  throws, not even in debug.
- **Disabled extensions:** Folio declares the permissions of every discovered extension.
  Those of extensions that are not enabled are disabled with the reason
  `extension {name}`.
- **Model types of an extension** get the standard type permissions only while they are
  enabled. While disabled, the standard names are declared and disabled the same way.

- [ ] **Step 1: Write the failing tests:**
  - `test_disabled_permission_denied_not_thrown_in_debug`
  - `test_disabled_logged_once`
  - `test_extension_permission_declared_with_group`
  - `test_permission_outside_extension_namespace_throws`
  - `test_disabled_extension_permissions_denied`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --filter 'AccessTest|PermissionsTest'`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(auth,folio): extensions declare permissions; permissions of disabled extensions are denied, never a 500`

### Task 13: Live settings

**Files:**
- Create:
  - `packages/folio/src/Extension/Settings/ExtensionSettings.php`
  - `packages/folio/src/Field/Types/EnvFieldType.php`
  - `packages/folio/templates/admin/fields/env.twig`, if field templates live in files.
    Otherwise follow the existing field type pattern.
- Modify:
  - `packages/folio/src/Extension/Extension.php` (add `settings(): array`, default `[]`)
  - `FolioServiceProvider`:
    - register the settings type per enabled extension with a non-empty `settings()` and
      the `settings` part enabled;
    - add the Twig function `folio_setting(string $extension, string $key)`;
    - register the field type `env`.
- Test: `packages/folio/tests/Extension/SettingsTest.php`

**Interfaces:**
- **`Extension::settings(): array<string, array>`** returns a field map in the model JSON
  `fields` format. A field may carry `default`. Translatable fields work as in content.
- **The settings type:** Folio defines it through Tasks 7 and 8:
  - key `{name}_settings`, label = extension label;
  - `singleton: true`, `publishing: false`, `section: 'settings'`;
  - table `folio_settings_{name}`, storage `default`;
  - it transfers like content.
- **URL and permissions:** it is edited at `{prefix}/{name}_settings`, the existing
  singleton route. Its permissions are the standard `{name}_settings.view` and
  `{name}_settings.edit`.
  *Plan deviation from the extensions spec* (`{extension}.settings`): this reuses the
  singleton route and type permissions unchanged. The cost is one more permission name in
  the roles screen.
- **`ExtensionSettings`:**
  - `ExtensionSettings::get(string $extension, string $key, ?string $locale = null): mixed`
    returns the stored value, else the field's `default`, else `null`.
  - An unknown key is an `ExtensionException` in debug and `null` otherwise.
  - It reads the working record (settings are not publishing).
- **The `env` field type:**
  - Config: `env: "NAME"`.
  - The form shows the env name and "set" or "not set". It never shows the value.
  - `fromRequest` always returns `null`, and its key is never written to the record.
  - `ExtensionSettings::get` returns `getenv(NAME) ?: null` for env fields.

- [ ] **Step 1: Write the failing tests:**
  - `test_settings_form_listed_under_settings_section`
  - `test_defaults_before_first_save`
  - `test_saved_value_wins_over_default`
  - `test_translatable_setting_per_locale`
  - `test_env_field_never_stored`: POST a value for an env field; the stored record has no
    such key and the page doesn't contain the value.
  - `test_env_field_shows_set_state`
  - `test_settings_exported_like_content`
  - `test_unknown_key_throws_in_debug`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Folio --filter SettingsTest`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them again.** Run `vendor/bin/phpunit --testsuite Folio`.
  Expected: PASS.
- [ ] **Step 5: Commit.** `feat(folio): live extension settings with defaults, translatable values and env references`

### Task 14: Extensions screen, CLI, install state

**Files:**
- Create:
  - `packages/folio/src/Extension/ExtensionState.php`
  - `packages/folio/src/Http/ExtensionsController.php`
  - `packages/folio/templates/admin/extensions.twig`
  - `packages/folio/src/Console/ExtensionsListCommand.php`, `ExtensionsEnableCommand.php`,
    `ExtensionsDisableCommand.php`, `ExtensionsApplyCommand.php`,
    `ExtensionsCheckCommand.php`
- Modify:
  - `FolioRoutes::admin` (`GET {prefix}/extensions`, `POST {prefix}/extensions/{name}`)
  - `FolioPermissionMiddleware::RULES` (both → `folio.extensions`, declared in
    `declarePermissions`)
  - `FolioCommands::all()`
  - `AdminNav` (Settings → Extensions)
- Test:
  - `packages/folio/tests/Extension/ExtensionsScreenTest.php`
  - `packages/folio/tests/Console/ExtensionsCommandsTest.php`

**Interfaces:**
- **`ExtensionState`:** installed versions per environment, stored as records of an
  internal `TypeDefinition` (table `folio_extension_state`, storage `default`). It is not
  in the `TypeCatalog`, so it is never exported.
  - `installedVersion(string $name): ?string`
  - `markInstalled(string $name, string $version): void`
- **`folio:extensions:apply`:**
  - It runs `TableSync::sync()` and prints its lines.
  - Then, for each enabled extension whose installed version ≠ its version, it calls
    `install($container)` and then `markInstalled`.
  - It is idempotent; a second run prints `nothing to do`.
- **`folio:extensions:enable <name> [--part=<id>]` and `disable <name> [--part=<id>]`**
  write `extensions.json`. Disabling keeps all data.
- **`folio:extensions:list`** prints name, version, enabled, installed version and parts
  with their state.
- **`folio:extensions:check`:**
  - It boots with debug forced on, so every error from Tasks 8, 10, 11 and 12 throws.
  - It prints `ok` and exits 0, or prints the error and exits 1.
- **The screen:**
  - It lists the same information as `list`.
  - It shows toggle forms only when `config/folio.php` `extensions.allow_admin_changes`
    (default: `app.debug`) is true. Otherwise it is read-only, with a note that the file
    is the source.
  - Toggling writes the file. It does not run `install()`; that is `apply`'s job and the
    screen says so.

- [ ] **Step 1: Write the failing tests:**
  - `test_apply_installs_once_per_version`
  - `test_apply_runs_table_sync`
  - `test_enable_disable_write_file`
  - `test_check_reports_error_exit_1`
  - `test_list_shows_parts`
  - `test_screen_read_only_in_production`
  - `test_screen_toggle_writes_file_when_allowed`
  - `test_extension_state_not_exported`
- [ ] **Step 2: Run them.** Run `vendor/bin/phpunit --testsuite Folio --filter 'ExtensionsScreen|ExtensionsCommands'`.
  Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit.** `feat(folio): Extensions screen and folio:extensions:{list,enable,disable,apply,check}`

### Task 15: Documentation and a working example

**Files:**
- Modify:
  - `packages/folio/README.md` (sections Extensions, Screens, Type customizations,
    Settings, User types and transfer, SQL storage)
  - `packages/data/README.md` (migration registry, schema introspection)
  - `packages/core/README.md` (Throttle)
  - `packages/devtools/README.md` (migrate)
- Create: `examples/folio-demo/app/Folio/Demo/` with:
  - one screen;
  - one `#[FolioType]` customization on an existing demo type (a panel and an action);
  - one setting;
  - plus `examples/folio-demo/config/folio/extensions.json` enabling it.
- Test: `packages/folio/tests/Integration/DemoExtensionTest.php`. It boots the demo app,
  runs `folio:extensions:check` (exit 0), and renders the screen and the settings form.

- [ ] **Step 1: Write the failing test.**
  `test_demo_extension_checks_and_renders`. It FAILS because the demo extension doesn't
  exist yet.
- [ ] **Step 2: Create the demo extension and write the README sections.** Each README
  example is copied from the demo or the tests, so it is known to work.
- [ ] **Step 3: Run all suites.** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 4: Check the existing sites, local only.** Link smeyer.me and
  smart-stack.works against the branch (path repos are already symlinked). Open
  `https://smeyer.dev.smyr/folio` and `https://smartstack.dev.smyr/folio` and log in
  with the local test login.
  - Expected: the sidebar and pages are unchanged.
  - Expected: `php preflow folio:extensions:check` prints `ok` in both.
- [ ] **Step 5: Commit.** `docs: extensions, settings, SQL storage, throttle, migrate; folio-demo extension`
