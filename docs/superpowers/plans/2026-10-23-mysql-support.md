# MySQL as a First-Class SQL Storage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Everything a Preflow site does on SQLite storage (migrations, Folio tables, publishing, throttle, codes, tokens, remember-me, members, assets, export/import) works the same on MySQL/MariaDB, released as v0.25.0.

**Architecture:** A `SchemaGrammar` per server (`SqliteGrammar` reproducing today's SQL byte for byte, `MysqlGrammar` new) owns all DDL and introspection; `Schema`, `Table`, `Migrator` and Folio's `TableSync` delegate to it. A shared test helper gives every storage-parameterised test a MySQL variant that runs when `MYSQL_*` env is set and skips otherwise.

**Tech Stack:** PHP 8.4, PDO (sqlite, mysql), PHPUnit 11, MariaDB 13 locally (production MariaDB 10.6).

**Spec:** `docs/superpowers/specs/2026-10-23-mysql-support-design.md`

## Global Constraints

- SQLite behaviour and SQLite DDL do not change: same SQL text, same tables, no new pending migrations for existing sites.
- MySQL types: `uuid()` VARCHAR(64), `string($n = 255)` VARCHAR($n), `text()` TEXT, `json()` LONGTEXT, `integer()` BIGINT, `boolean()` TINYINT(1), `timestamps()` VARCHAR(32) nullable; `preflow_migrations`: `migration VARCHAR(191) PRIMARY KEY, ran_at VARCHAR(32) NOT NULL`.
- MySQL tables: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`; identifiers in backticks.
- Folio tables on MySQL: id `VARCHAR(191) PRIMARY KEY`, every other column nullable `LONGTEXT`.
- Indexing a TEXT column on MySQL throws `\LogicException` with the message `Cannot index TEXT column "{table}.{column}" on MySQL: use string() for indexed columns.`
- An unsupported PDO driver throws `\LogicException("No schema grammar for PDO driver \"{$driver}\".")`.
- MySQL connection: `PDO::ATTR_ERRMODE => ERRMODE_EXCEPTION`, `PDO::MYSQL_ATTR_FOUND_ROWS => true`, session `sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'`.
- Upsert keeps `VALUES(col)` (MariaDB has no row-alias syntax).
- MySQL tests: run when `MYSQL_HOST` and `MYSQL_DATABASE` are set (plus `MYSQL_USER`, `MYSQL_PASS`), else `markTestSkipped('MySQL not configured (set MYSQL_HOST, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASS)')`. The local values are in the gitignored `.env.mysql-test` at the repo root.
- Full suite command: `vendor/bin/phpunit` (must stay green without MySQL env: MySQL tests skip). MySQL command: `composer test:mysql`.
- Existing code style: `declare(strict_types=1)`, `final` classes, short docblocks that say why, no new dependencies.

## Review Focus

- A server whose default `sql_mode` has `ANSI_QUOTES` or lacks strict mode: our session `sql_mode` wins (Task 3 test sets a hostile global-like session mode first and reconnects through `Application`).
- An existing SQLite site: identical DDL (Task 2 characterisation test captures `sqlite_master.sql` for every package migration before the refactor).
- Two processes redeeming the last code or hitting the throttle at once on MySQL (Task 5 runs the existing fork-based concurrency tests on MySQL too).
- A Folio `text` field holding 100 KB (Task 4 stores and reads back 100,000 characters).
- An `UPDATE` that rewrites a row with identical values reports 1 affected row on MySQL (Task 3).

---

### Task 1: MySQL test database helper

**Files:**
- Create: `packages/testing/src/MysqlTestDatabase.php`
- Create: `bin/test-mysql` (executable)
- Modify: `composer.json` (add `"scripts": {"test:mysql": "bin/test-mysql"}`)
- Modify: `packages/data/tests/Driver/MysqlDriverTest.php` (use the helper)
- Test: `packages/testing/tests/MysqlTestDatabaseTest.php` (add the testsuite to `phpunit.xml` if `packages/testing/tests` is not listed)

**Interfaces:**
- Produces:
  - `Preflow\Testing\MysqlTestDatabase::pdo(): \PDO` — connects with `MYSQL_*` env (`mysql:host=…;port=…;dbname=…;charset=utf8mb4`, port from `MYSQL_PORT` default 3306), applies the Global Constraints connection settings (FOUND_ROWS as a constructor option, `SET SESSION sql_mode=…`), or calls `TestCase::markTestSkipped()` with the exact skip message. Static; memoises one connection per process (forked children must call `MysqlTestDatabase::reconnect(): \PDO`).
  - `MysqlTestDatabase::configured(): bool`
  - `MysqlTestDatabase::dropAllTables(\PDO $pdo): void` — drops every table in `DATABASE()` (FOREIGN_KEY_CHECKS off around it).
  - `MysqlTestDatabase::dataConfig(): array{host: string, port: string, database: string, username: string, password: string}` — for tests that write a `config/data.php` `drivers.mysql` entry.
  - `bin/test-mysql`: sources `.env.mysql-test` (fails with "Create .env.mysql-test with MYSQL_HOST, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASS" if missing), exports the vars, runs `vendor/bin/phpunit --group mysql "$@"`.
  - Every MySQL test carries `#[Group('mysql')]`.

- [ ] **Step 1: Write the failing tests** in `MysqlTestDatabaseTest` (`#[Group('mysql')]`):
  - `test_pdo_connects_with_strict_session_mode`: `pdo()->query('SELECT @@SESSION.sql_mode')` contains `STRICT_TRANS_TABLES` and not `ANSI_QUOTES`.
  - `test_drop_all_tables_empties_the_database`: create tables `t_a`, `t_b`; `dropAllTables()`; `information_schema.TABLES` count for `DATABASE()` is 0.
  - `test_found_rows_counts_matched_rows`: create `t_f (id INT PRIMARY KEY, v INT)`, insert `(1, 5)`, `UPDATE t_f SET v = 5 WHERE id = 1` → `rowCount()` is 1.
- [ ] **Step 2: Run** `composer test:mysql -- --filter MysqlTestDatabaseTest` → FAIL (class not found). Also run `vendor/bin/phpunit --filter MysqlTestDatabaseTest` without env → 3 skipped.
- [ ] **Step 3: Implement** the helper and script; switch `MysqlDriverTest::test_mysql_integration` to `MysqlTestDatabase::pdo()` and the group.
- [ ] **Step 4: Run** both commands again → PASS with env, SKIPPED without.
- [ ] **Step 5: Commit** `test: MySQL test database helper and composer test:mysql`

### Task 2: Schema grammars, migrations on MySQL

**Files:**
- Create: `packages/data/src/Migration/Grammar/SchemaGrammar.php` (interface), `SqliteGrammar.php`, `MysqlGrammar.php`, `Grammars.php`
- Modify: `packages/data/src/Migration/Schema.php`, `Table.php`, `Migrator.php`
- Test: `packages/data/tests/Migration/SqliteDdlCharacterisationTest.php`, `packages/data/tests/Migration/MysqlGrammarTest.php` (pure SQL strings), `packages/data/tests/Migration/MysqlMigrationsTest.php` (`#[Group('mysql')]`), `MigratorTest.php` (replace the guard test)

**Interfaces:**
- Consumes: Task 1 `MysqlTestDatabase`.
- Produces:
  - `interface SchemaGrammar` with:
    - `quote(string $identifier): string`
    - `columnType(string $kind, ?int $length = null): string` — `$kind` ∈ `uuid|string|text|json|integer|boolean|timestamp`
    - `createTable(string $table, list<array{name: string, kind: string, length: ?int, nullable: bool, primary: bool}> $columns): string`
    - `createIndex(string $table, string $column): string` (index name stays `idx_{table}_{column}`)
    - `dropTable(string $table): string`
    - `hasTable(\PDO $pdo, string $table): bool`
    - `columns(\PDO $pdo, string $table): list<string>`
    - `addColumn(string $table, string $column, ?string $type = null): string` (null type = `TEXT` on SQLite, `LONGTEXT` on MySQL)
    - `createMigrationsTable(): string`
    - `canIndex(string $kind): bool`
  - `Grammars::for(\PDO $pdo): SchemaGrammar` — by `PDO::ATTR_DRIVER_NAME`; unknown driver throws the Global Constraints message.
  - `Table` records `kind`/`length` per column instead of a type string; `Table::toSql()` is removed in favour of `Schema` calling `$grammar->createTable($table->getName(), $table->columns())`; add `Table::columns(): list<array{...}>` (same shape as above). If anything outside `Schema` calls `toSql()`, keep `toSql(?SchemaGrammar $grammar = null)` defaulting to `SqliteGrammar` instead.
  - `Schema::addColumn(string $table, string $column, ?string $type = null)` (default changes from `'TEXT'` to null; SQLite still gets TEXT).

- [ ] **Step 1: Characterise SQLite first.** `test_package_migrations_produce_unchanged_sqlite_ddl`: on `sqlite::memory:`, run every file in `packages/*/migrations/*.php` through `Migrator`, then read `SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY name`; assert equality with an expected array written inline in the test from a run against the *current* code. Run → PASS (it pins today's SQL).
- [ ] **Step 2: Write the failing tests:**
  - `MysqlGrammarTest`: `createTable('t', [uuid id primary, string name nullable, string code len 32, integer n, boolean b, json j, text x])` equals exactly
    ``CREATE TABLE `t` (`id` VARCHAR(64) PRIMARY KEY, `name` VARCHAR(255), `code` VARCHAR(32) NOT NULL, `n` BIGINT NOT NULL, `b` TINYINT(1) NOT NULL, `j` LONGTEXT NOT NULL, `x` TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci``
    (one line; SQLite keeps its own multi-line format); `createIndex('t','name')` is ``CREATE INDEX `idx_t_name` ON `t` (`name`)``; `canIndex('text')`, `canIndex('json')` false; `canIndex('string')` true.
  - `MysqlMigrationsTest` (`dropAllTables` in setUp and tearDown):
    - `test_every_package_migration_runs_on_mysql_twice`: `Migrator` over all `packages/*/migrations` → no exception; `pending()` afterwards is `[]`; a second `Migrator(...)->migrate()` is a no-op; `information_schema.TABLES` contains `preflow_migrations`, `preflow_throttle`, `auth_tokens`, `auth_remember`, `code_redemption`.
    - `test_introspection_and_add_column`: `hasTable('code_redemption')` true, `hasTable('nope')` false, `columns('code_redemption')` is `['id','code_id','purpose','redeemer','redeemed_at']`; `addColumn('code_redemption','note')` then `columns` ends with `note` and its `DATA_TYPE` is `longtext`.
    - `test_indexing_a_text_column_throws`: `Schema::create('t', fn (Table $t) => $t->uuid('id')->primary()->text('body')->index())` → `\LogicException` with the Global Constraints message for `t.body`.
  - `MigratorTest`: replace the guard test by `test_unknown_driver_is_refused` (a PDO subclass reporting `pgsql`) expecting `No schema grammar for PDO driver "pgsql".`
- [ ] **Step 3: Run** `vendor/bin/phpunit packages/data/tests/Migration` and `composer test:mysql -- --filter Mysql` → the new tests FAIL.
- [ ] **Step 4: Implement** the grammars and delegate from `Schema`, `Table`, `Migrator` (`ensureMigrationsTable` uses `createMigrationsTable()` with `IF NOT EXISTS`; the guard goes).
- [ ] **Step 5: Run** `vendor/bin/phpunit` (full suite green, characterisation test unchanged) and `composer test:mysql` → PASS.
- [ ] **Step 6: Commit** `feat(data): schema grammars; migrations run on MySQL`

### Task 3: MySQL connection settings

**Files:**
- Modify: `packages/core/src/Application.php` (`createMysqlDriver`)
- Modify: `packages/data/src/Driver/MysqlDriver.php`
- Test: `packages/core/tests/MysqlConnectionTest.php` (`#[Group('mysql')]`)

**Interfaces:**
- Consumes: Task 1 `MysqlTestDatabase::dataConfig()`.
- Produces: `MysqlDriver::SQL_MODE` public const string with the Global Constraints value; `MysqlDriver::connectionOptions(): array<int, mixed>` static (ERRMODE, FOUND_ROWS) for anyone building the PDO; `MysqlDriver::__construct` keeps its signature and runs `SET SESSION sql_mode = …` and `SET NAMES 'utf8mb4'`. `Application::createMysqlDriver` passes `MysqlDriver::connectionOptions()` to `new \PDO`.

- [ ] **Step 1: Write the failing tests** (boot a temp app dir with `config/data.php` `drivers.mysql` = `MysqlTestDatabase::dataConfig()`, `default` = `mysql`; get `DataManager`, then the driver's `pdo()`):
  - `test_application_connection_uses_strict_mode_and_found_rows`: `SELECT @@SESSION.sql_mode` equals `MysqlDriver::SQL_MODE` (order as MySQL reports it; compare as sets of comma-separated flags); an `UPDATE` with unchanged values via `DataManager::execute()` returns 1.
  - `test_session_mode_overrides_ansi_quotes`: a hand-made PDO with `SET SESSION sql_mode='ANSI_QUOTES'` passed to `new MysqlDriver($pdo)` → afterwards `sql_mode` has no `ANSI_QUOTES` and a backtick-quoted insert/select through the driver works.
- [ ] **Step 2: Run** `composer test:mysql -- --filter MysqlConnectionTest` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `composer test:mysql` and `vendor/bin/phpunit` → PASS.
- [ ] **Step 5: Commit** `feat(data): strict, predictable MySQL sessions (sql_mode, found rows)`

### Task 4: Folio tables and publishing on MySQL

**Files:**
- Modify: `packages/folio/src/Storage/TableSync.php` (delegate to `Grammars::for()`; the guard goes; docblock: SQLite TEXT, MySQL VARCHAR(191) id + LONGTEXT)
- Modify: `packages/folio/tests/Support/PublishingScaffold.php` (accept `'mysql'`: writes `drivers.mysql` from `MysqlTestDatabase::dataConfig()`, models with `"storage": "mysql"`; `removeScaffold()` drops all tables when mysql)
- Create: `packages/folio/tests/Integration/MysqlAppTest.php` (`#[Group('mysql')]`)

**Interfaces:**
- Consumes: Task 2 `SchemaGrammar::createTable/columns/addColumn/hasTable`, `Grammars::for`; Task 1 helper.
- Produces: nothing new for later tasks.

- [ ] **Step 1: Write the failing tests** in `MysqlAppTest`: copy the four tests of `SqliteAppTest` with `scaffoldPublishing('mysql')`, plus:
  - `test_folio_table_columns_on_mysql`: after the migrate step, `information_schema.COLUMNS` for `post`: `uuid` is `varchar(191)` with `COLUMN_KEY = 'PRI'`, `title` and `sections` are `longtext`, nullable.
  - `test_large_text_field_round_trip`: publish a post whose `body` is `str_repeat('ä', 100000)`; read back via `DataManager` → identical string.
  - `test_localized_query_and_numeric_sort`: three posts with `_order` 2, 10, 1 and `de`/`en` titles; `queryType('post')` ordered by `_order` ascending (numeric) yields 1, 2, 10; a localized `where title = 'Hallo'` in `de` finds the German one.
  - `test_export_import_round_trip`: `folio:export` to a temp dir, `dropAllTables`, migrate step again, `folio:import` → the published post renders at `/hello` again.
- [ ] **Step 2: Run** `composer test:mysql -- --filter MysqlAppTest` → FAIL ("supports SQLite only for now").
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `composer test:mysql` and `vendor/bin/phpunit` → PASS (SqliteAppTest unchanged).
- [ ] **Step 5: Commit** `feat(folio): table sync and publishing on MySQL`

### Task 5: Throttle, codes, tokens, remember-me, members and assets on MySQL

**Files:**
- Modify tests only, unless they expose bugs: `packages/core/tests/Throttle/ThrottleTest.php`, `packages/codes/tests/CodesTest.php`, `packages/auth/tests/Tokens/TokensTest.php`, `packages/auth/tests/Remember/RememberMeTest.php`, `packages/folio/tests/Users/RecordUserProviderTest.php`, the asset library test that covers an SQL-stored asset index (find it under `packages/folio/tests/Assets` or `Integration/AssetLibraryAppTest.php`).
- Fix in `src/` whatever the MySQL runs reveal, each with the failing MySQL test first.

**Interfaces:**
- Consumes: Task 1 helper, Task 2 migrations on MySQL, Task 3 connection settings, Task 4 Folio tables.

- [ ] **Step 1: Add MySQL variants.** Where a test loops over storages (`foreach (['sqlite', 'json'] …)`), add `'mysql'` when `MysqlTestDatabase::configured()`; where a test class is SQLite-only, add a `#[Group('mysql')]` subclass or data-provider case that builds the same `DataManager` with a `MysqlDriver(MysqlTestDatabase::pdo())` (driver key `mysql`) and migrates with the package migration. Must include on MySQL:
  - throttle: `test_allows_until_max_then_blocks`, window restart, and the concurrent-hits test if one exists (else add `test_concurrent_hits_are_all_counted_on_mysql`: 4 forked children × 25 hits → 100);
  - codes: `test_concurrent_redeem_cannot_exceed_max_uses` and the max-uses / expiry tests;
  - tokens: consume-once and the `pcntl_fork` race test (children call `MysqlTestDatabase::reconnect()`);
  - remember-me: rotation, theft detection, `test_concurrent_restore_read_after_commit_keeps_member_logged_in`;
  - `RecordUserProvider`: sign-in by email and `test_roles_from_json_string_on_sqlite`'s MySQL twin;
  - asset library: upload stores its index row on MySQL and `find()` returns it.
- [ ] **Step 2: Run** `composer test:mysql` → note every failure; for each, write down the cause in the commit body.
- [ ] **Step 3: Fix** each failure at its source (driver, dialect or the store), keeping SQLite SQL unchanged.
- [ ] **Step 4: Run** `composer test:mysql` and `vendor/bin/phpunit` → PASS.
- [ ] **Step 5: Commit** `test: throttle, codes, auth and Folio users on MySQL` (plus a `fix(...)` commit per source fix).

### Task 6: Docs and release v0.25.0

**Files:**
- Modify: `packages/data/README.md` (create the MySQL section; if the data package has no README, add it to the docs page that documents `config/data.php`), `ROADMAP.md` (v0.25.0 entry, "Current State" version line), `packages/folio/composer.json` and `packages/devtools/composer.json` (`"preflow/data": "^0.25 || @dev"`)
- Create: `docs/superpowers/plans/2026-10-23-release-0.25-checklist.md` (same shape as the 0.24 checklist)

- [ ] **Step 1: Write the docs:** config example (`drivers.mysql` keys, `DB_*` env fallbacks, `default`/`aliases`), utf8mb4 and the collation, type table from the spec, "TEXT columns can't be indexed: use string()", connection settings and why, how to run `composer test:mysql`.
- [ ] **Step 2: Run** `vendor/bin/phpunit` and `composer test:mysql` → both green; record the counts in the checklist.
- [ ] **Step 3: Commit** `chore: prepare 0.25.0 (MySQL docs, roadmap, constraints)`
- [ ] **Step 4: Release** as for 0.24.1: merge the branch into `main` (fast-forward), tag `v0.25.0`, push `main` and the tag, wait for the split workflow to succeed, check Packagist shows v0.25.0 for `preflow/data`, `preflow/folio`, `preflow/devtools`; tick the checklist and commit it.
