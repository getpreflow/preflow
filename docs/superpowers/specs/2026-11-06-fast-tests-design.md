# Fast test suites for every Preflow site (preflow/testing 0.56)

Owner, 2026-11-06: "optimize the test suite … so we don't always have to go full 33+ minutes for each little change.
I don't want to compromise but also I think it should be possible." Then: "faster tests for all sites" — approved
design in chat the same day.

## Why

Every site's suite spends most of its time on set-up, not on testing. holz-objekte.org proved it: each test dropped
27 tables and ran migrate and seed (2.6 s of 2.7 s). Two changes there took the suite from ~33 min to 3:03 without
removing or weakening a single test:

1. **Set-up snapshots** (holz `tests/Support/SiteSnapshot.php`): a set-up step (migrate, seed) is run once; a later
   test that starts from *exactly* the same state gets the step's result restored instead.
2. **Parallel workers** (holz `tests/Support/TestWorker.php`, paratest): each worker gets its own databases and caches.

The other sites have the same shape: BG Genius runs `preflow migrate` as a sub-process before almost every test
(6 min), smart-stack.works runs 104 tests in their own processes (87 s), smeyer.me 59 s. Today each site would need
its own copy of holz's helpers. This design moves them into `preflow/testing` so every site uses one tested
implementation.

## Goals

- `preflow/testing` provides the snapshot mechanism and the worker naming, generic: files and SQLite (a file) work
  with no extra code; MySQL through a database adapter the package ships.
- Each of the four sites runs its full suite with `composer test` (paratest, parallel) and `composer test:serial`.
- Measured targets (estimates, to be confirmed by the build): holz ≤ 3:05 (no regression), BG Genius < 1 min,
  smeyer.me and smart-stack.works < 20 s each.

## Non-goals / anti-goals

- **No skipped or weakened tests.** No "core" subset is introduced; the full run stays the gate before every
  release and deploy. (A core subset may come later if still wanted; it is not part of this.)
- **Never a stale or wrong snapshot.** A restored step must leave exactly what the real step left. Anything a step
  could depend on that differs (an environment variable, a file, a table definition or row) means the step runs for
  real. Correct by construction is the rule; speed comes second.
- No change to production code paths, no new runtime dependency for sites (paratest is require-dev only).
- No test-ordering dependence: a test passes alone, in any order, serial or parallel.

## Constraints

- PHP 8.4+, PHPUnit 11.5, paratest 7.8 (works with PHPUnit 11.5; installed in holz).
- Snapshots live for one run only: one run id per PHPUnit/paratest run, inherited by separate-process tests
  (env var), removed at the end by the process that made it. A worker process makes its own id.
- Never touch real databases: the MySQL adapter only works on a database whose name the caller passes and confirms
  (holz: `holzobjekte_test` / `holzobjekte_test_N`).
- Local DB rights for per-worker MySQL databases are granted by the owner (done for holz:
  `holzobjekte\_test\_%`, `holzobjekte\_src\_test\_%`).

## What the package provides (decided)

Namespace `Preflow\Testing\Fast` (new), in `packages/testing/src/Fast/`.

### `SetupSnapshots`

Runs a set-up step or restores its result.

- `step(string $name, callable $step): mixed` — the state key is `$name` plus: every environment variable
  (`getenv()` and `$_ENV`, the run id left out), the content of every file under the registered roots, and, when a
  database adapter is set, every table's definition and checksum. On a hit the roots' files are restored (exact:
  files the step didn't leave are removed) and the step's return value is returned; the database is restored lazily
  (below). On a miss the step runs and its outcome is captured — unless the outcome names one of the roots' real
  paths (rows or return value) or a placeholder appears in file contents, then nothing is kept for later tests.
- Roots: `root(string $placeholder, string $dir)` — directories that are this test's own (a temp dir with a random
  name). Their random names are replaced by the placeholder in the key, so another test's equal state matches.
  Files can be ignored by name (`ignore('error.log')`: logs nothing depends on).
- Databases: `database(string $name, SnapshotDatabase $db)`, one or more. Lazy: a restored step's tables are written when something next touches
  the database (`flush()`, which the site calls from its own `app()` / PDO helpers), so a restored migrate followed by
  a restored seed writes the tables once. `reset()` marks the database to be emptied lazily (holz's `bootSite()`).
- Storage: one file per snapshot in `sys_get_temp_dir()/preflow_snapshots_<run>/`, plus the last 4 in memory.
- `PREFLOW_TEST_SNAPSHOTS=0` turns snapshots off (every step runs for real); without a run id they are off too.
- `SetupSnapshots::bootstrap()` — for a site's `tests/bootstrap.php`: sets `PREFLOW_TEST_RUN` when absent and
  registers the clean-up for the process that set it.

### `SnapshotDatabase` and `MysqlSnapshotDatabase`

- Interface: `state(): array` (definitions + checksums), `capture(): array`, `restore(array $tables): void`,
  `empty(): void`.
- `MysqlSnapshotDatabase(\Closure $pdo, string $database)`: holz's implementation moved over — `SHOW CREATE TABLE` +
  `CHECKSUM TABLE` for the state; restore drops extra tables, recreates changed ones, `DELETE` + one-transaction
  `INSERT` for unchanged definitions, `AUTO_INCREMENT` reset only where the counter moved. Refuses a connection whose
  current database isn't `$database`.
- SQLite needs no adapter: its database is a file under a root.

### `TestWorker`

- `TestWorker::token(): ?int` (paratest's `TEST_TOKEN`), `suffix(): string` (`''` or `_N`),
  `name(string $base): string` (`holzobjekte_test` → `holzobjekte_test_3`), `dir(string $base): string`
  (a per-worker temp directory, created).
- `TestWorker::createMysqlDatabase(\PDO $server, string $name)`: `CREATE DATABASE IF NOT EXISTS`, with the
  error message holz has today naming the `GRANT` an admin must run once.

### Docs

`packages/testing/README.md`: a "Fast suites" section — the snapshot rule (what makes a state equal), how to wire
`bootSite()`/`migrate`/`seed` through it, roots, the MySQL adapter, workers and paratest, `composer test` scripts,
`PREFLOW_TEST_SNAPSHOTS=0`. ROADMAP entry, release 0.56.0.

## The sites (decided)

Each site: paratest in require-dev, `composer test` (`paratest --processes=6`) and `composer test:serial`,
`tests/bootstrap.php` calls `SetupSnapshots::bootstrap()`. Per site, which steps go through `step()` is found by
measuring (a timing test like the one used in holz), the obvious candidates:

- **holz-objekte.org:** replace `tests/Support/SiteSnapshot.php` and `TestWorker.php` with the package; behaviour
  unchanged (same tests, `SiteSnapshotTest` moves its generic parts to the package and keeps the holz wiring test).
- **BG Genius:** `BootsFolioApp::folioApp()` — schema load + `migrate` (+ `folio:extensions:apply`/`check` for staff)
  as steps over the temp root (SQLite file inside). Per-worker Folio cache already exists (`FOLIO_CACHE_PATH`).
- **smart-stack.works, smeyer.me:** JSON + SQLite under the per-test `DATA_PATH`; the fixture import and Folio
  set-up as steps; paratest for the separate-process tests.

## Calls made after the builder check (mine, not the owner's)

1. **Effects outside the roots.** A step may only change files under its roots and the registered databases;
   everything else it writes must be a *derived cache* (rebuilt from code, config and that state, e.g. Folio's
   extension and block caches). That is how holz runs today, proven by its full suite. A site whose step writes
   anything else registers that directory as a root (then its content is part of the key). The README states the rule.
2. **Telling the site a step was restored.** `onRestore(\Closure $callback)`: called after a hit's files are in place,
   so the scaffold drops its booted app, PDO handles and in-process caches (holz: `$this->siteApp = null`). Sites
   must not hold an open SQLite connection across a step; the callback is where they drop it (SQLite `-wal`/`-shm`
   files are files under the root like any other).
3. **Return values.** Kept only when they survive `serialize()`; a step whose result can't be serialized (closures,
   objects holding the container) runs for real every time and nothing is stored. Steps that should be cached return
   scalars or arrays (smart-stack's seed returns what a test needs from its `ImportReport`, not the object).
4. **Several databases.** `database(string $name, SnapshotDatabase $db)` may be called more than once; every
   registered database is in the key and restored. holz registers `holzobjekte_test`; its source database
   (`holzobjekte_src_test`) is only touched by import tests, which aren't snapshotted.
5. **Run id under paratest.** Whoever loads the bootstrap first sets it; workers either inherit it (one shared
   snapshot directory, safe: writes are atomic renames and the key holds the worker's database name and paths) or
   make their own. Either is correct; the process that set the id removes the directory.
6. **Per-worker Folio cache.** No special helper: `TestWorker::dir('folio_cache')` gives the per-worker directory
   holz uses today.

## Left to the builder

- Exact class and method names inside the decided shape; internal file format of a snapshot.
- Which further set-up steps per site are worth a step (measure first).
- Whether `SetupSnapshots` is used as an object held by the site's scaffold trait (preferred: no trait in the
  package, so sites keep their own scaffolds) — builder's call if a trait reads better.

## Verification

- Package: the holz `SiteSnapshotTest` cases generalised (restore equals real step after a dirty test; env, file,
  table changes run the step; renamed roots match; state survives a separate process), MySQL cases skipped without
  MySQL (`MysqlTestDatabase::configured()`), file cases always.
- Each site: full suite green serial and parallel, timings before/after recorded in the ROADMAP entry.
