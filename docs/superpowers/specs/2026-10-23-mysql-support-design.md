# MySQL as a first-class SQL storage — Design

**Date:** 2026-10-23
**Release:** v0.25.0
**Why now:** step 1 of the holz-objekte.org port
(`docs/superpowers/plans/2026-10-23-holzobjekte-port-overview.md`). That site keeps objects,
members, likes, orders and the asset index on MySQL (MariaDB 10.6 on giantbits.com), so
everything a site does on SQLite today must work the same on MySQL.
**Status:** written without a question round (Steffen asked to go straight to the plan); the
calls below are mine.

## Today

- `MysqlDriver` + `MysqlDialect` exist: CRUD, queries, upsert (`ON DUPLICATE KEY UPDATE`),
  localized columns, numeric sort. `Application` builds it from `config/data.php`
  `drivers.mysql` (`host`, `port`, `database`, `username`, `password`, or `DB_*` env).
- The throttle's atomic upsert already has a MySQL branch.
- **SQLite only, guarded:** `Migrator` (refuses non-SQLite since v0.24.1), `Schema`
  (`sqlite_master`, `PRAGMA table_info`, double quotes), `Table::toSql()` (TEXT everywhere,
  double quotes), the `preflow_migrations` table, and Folio's `TableSync` (throws "supports
  SQLite only for now").
- **Untested on MySQL:** codes (conditional `UPDATE`), auth tokens and remember-me (rowCount
  based), Folio publishing, the asset index, `RecordUserProvider`. One MySQL smoke test exists
  (`MysqlDriverTest::test_mysql_integration`, skipped unless `MYSQL_HOST` etc. are set).

## Goals

1. `preflow migrate` runs every package and site migration on MySQL and MariaDB, and running
   it again changes nothing.
2. Folio's `folio.tables` step creates and extends the tables of SQL-stored types on MySQL.
3. Everything that works on SQLite storage works on MySQL storage: CRUD, queries, sorting,
   localized values, publishing, throttle, codes, tokens, remember-me, member sign-in, the asset
   index, export/import.
4. A MySQL test run that anyone with a local MariaDB/MySQL can start with one command, and that
   is skipped cleanly without one.

## Non-goals

- PostgreSQL. A third grammar should be easy after this, not part of it.
- Altering or dropping columns, foreign keys, or a migration "down" runner.
- Changing SQLite behaviour or the SQLite DDL in any way (existing SQLite sites must see no
  difference: same SQL, same tables).
- CI with a database service (the repo's CI only splits packages today).

## Decisions

- **One grammar per server.** `Schema`, `Table`, `Migrator` and `TableSync` stop writing SQL
  themselves and ask a `SchemaGrammar` picked from the PDO driver name (`sqlite`, `mysql`;
  anything else throws "no schema grammar for <driver>"). `SqliteGrammar` reproduces today's SQL
  byte for byte.
- **MySQL column types:**

  | `Table` method | SQLite (unchanged) | MySQL |
  |---|---|---|
  | `uuid()` | TEXT | VARCHAR(64) |
  | `string($n = 255)` | TEXT | VARCHAR($n) |
  | `text()` | TEXT | TEXT |
  | `json()` | TEXT | LONGTEXT (not JSON: MySQL's JSON type rewrites key order and spacing) |
  | `integer()` | INTEGER | BIGINT (Unix times outlive 2038) |
  | `boolean()` | INTEGER | TINYINT(1) |
  | `timestamps()` | TEXT, nullable | VARCHAR(32), nullable |
  | `preflow_migrations` | `migration TEXT PRIMARY KEY, ran_at TEXT` | `migration VARCHAR(191) PRIMARY KEY, ran_at VARCHAR(32)` |

  Tables are created `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
  (MariaDB 10.6 has no `utf8mb4_0900_*`). Identifiers are backtick-quoted.
- **Indexing a TEXT column on MySQL is an error** at `Schema::create()` ("index a string()
  column, MySQL can't index TEXT"), not a silent prefix index.
- **Introspection on MySQL** uses `information_schema` scoped to `DATABASE()`: `hasTable`,
  `columns` (in `ORDINAL_POSITION` order), `addColumn` (nullable; default type TEXT on SQLite,
  LONGTEXT on MySQL).
- **Folio tables on MySQL:** id `VARCHAR(191) PRIMARY KEY`, every other column nullable
  `LONGTEXT` (block and rich-text values can pass TEXT's 64 KB; LONGTEXT costs 12 bytes of row
  size, so a 100-field type stays far below InnoDB's row limit).
- **Connection:** `createMysqlDriver` keeps its config keys and adds `PDO::ATTR_ERRMODE`
  exceptions, `PDO::MYSQL_ATTR_FOUND_ROWS => true` (an `UPDATE` that matches a row but leaves
  its values unchanged still counts as 1, as on SQLite, so rowCount-based code behaves the same)
  and an init command `SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'`
  (predictable across server defaults; no `ANSI_QUOTES`, backticks are used). Whether
  `MysqlDriver` itself sets these is the builder's call, as long as a hand-made `PDO` passed to
  it gets them too where PDO allows it after connecting.
- **Upsert stays `VALUES(col)`** (works on MariaDB and MySQL 8, deprecated but supported on
  MySQL 8.x; the row-alias syntax is MySQL-only).
- **Tests:** MySQL tests run when `MYSQL_HOST` and `MYSQL_DATABASE` are set (the existing
  convention, plus `MYSQL_USER`, `MYSQL_PASS`), else they are skipped with that message. They
  share one database and drop the tables they made. A gitignored `.env.mysql-test` and
  `composer test:mysql` run them locally. Coverage:
  - every package migration on MySQL, twice (second run: nothing pending);
  - `Schema` introspection and `addColumn`;
  - `TableSync` create + add column, and the Folio publishing scaffold on MySQL (a
    `MysqlAppTest` mirroring `SqliteAppTest`);
  - throttle under concurrent hits, code redemption limits, token consume, remember-me
    rotation and theft detection, `RecordUserProvider` sign-in, asset upload index;
  - localized query + numeric sort on a synced table;
  - `folio:export` / `folio:import` round trip from MySQL storage.
- **Docs:** `packages/data/README.md` (or the docs site page that covers storage) gets a MySQL
  section: config, env, utf8mb4, what migrations support; ROADMAP v0.25.0.

## Review focus

- A MariaDB server whose default `sql_mode` lacks strict mode, or has `ANSI_QUOTES`.
- A site that already ran migrations on SQLite: identical DDL, no new pending migrations.
- Two requests hitting the throttle or redeeming the last code at the same moment on MySQL.
- A Folio text field holding 100 KB of blocks JSON.
- An `UPDATE` that rewrites a row with the same values (rowCount 0 without FOUND_ROWS).
