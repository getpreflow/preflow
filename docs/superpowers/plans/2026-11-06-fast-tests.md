# Fast Test Suites Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Set-up snapshots and parallel-worker naming in `preflow/testing` (0.56), adopted by holz-objekte.org, BG Genius, smart-stack.works and smeyer.me so each runs its full suite in parallel with `composer test`.

**Architecture:** `Preflow\Testing\Fast\SetupSnapshots` runs a set-up step once per state and restores its outcome (files under registered roots, registered databases, the return value) for later tests that start from exactly the same state; `MysqlSnapshotDatabase` is the MySQL adapter; `TestWorker` names per-worker databases and directories for paratest. Sites keep their own scaffold traits and hold a `SetupSnapshots` object.

**Tech Stack:** PHP 8.4, PHPUnit 11.5, paratest 7.8 (sites' require-dev), MariaDB/MySQL (holz), SQLite/JSON files (others).

**Spec:** `docs/superpowers/specs/2026-11-06-fast-tests-design.md` (read it, including "Calls made after the builder check").

**Reference implementation:** holz-objekte.org `tests/Support/SiteSnapshot.php`, `TestWorker.php`, `SiteScaffold.php`, `tests/bootstrap.php`, `tests/Integration/SiteSnapshotTest.php` (`/Users/smyr/Sites/gbits/holz-objekte.org`). The package generalises this code; behaviour must stay identical for holz.

## Global Constraints

- No test is removed, skipped or weakened in any site; full suites stay green serial and parallel.
- A restored step leaves exactly what the real step left; any differing env var, file under a root, or table definition/row makes the step run for real.
- Env vars: `PREFLOW_TEST_RUN` (run id), `PREFLOW_TEST_SNAPSHOTS=0` (off). Snapshot dir: `sys_get_temp_dir() . '/preflow_snapshots_' . <run>`; files written via temp file + `rename()`.
- Return values are kept only if they survive `serialize()`; otherwise the step runs every time and nothing is stored.
- No production code path changes in any site; paratest only in require-dev. holz-only production change already exists (`config/folio.php` `cache_path` from `FOLIO_CACHE_PATH`).
- MySQL adapter refuses a PDO whose `SELECT DATABASE()` isn't the name it was given.
- Never run two holz suites (or a holz suite and a package MySQL test on `holzobjekte_test`) at the same time.
- Site commits never include BG's `config/ai.php`, `config/folio/extensions.json`, `database/*.sqlite` backups or `storage/`.

## Review Focus

1. A test sets an env var between two steps (holz sets `APP_DEBUG=0` after `bootSite`) — the second step's key must change; covered in Task 1 (`test_another_environment_runs_the_step`).
2. Two tests with different random root names but equal content — must hit; Task 1 (`test_roots_with_other_names_but_equal_content_hit`).
3. A step throws — the exception propagates, nothing is stored, and the next call runs the step again; Task 1 (`test_a_failing_step_is_not_stored`).
4. A step returns something unserializable (a closure) — it runs every time and returns its real value; Task 1 (`test_an_unserializable_result_runs_every_time`).
5. A restored migrate followed by a restored seed must write the database once and the second key must use the first restore's state; Task 2 (`test_two_restored_steps_write_the_tables_once`).

---

### Task 1: `SetupSnapshots` with roots, env and the run (files only)

**Files:**
- Create: `packages/testing/src/Fast/SetupSnapshots.php`
- Test: `packages/testing/tests/Fast/SetupSnapshotsTest.php`

**Interfaces:**
- Produces (namespace `Preflow\Testing\Fast`):
  - `final class SetupSnapshots`
  - `__construct(?string $run = null, ?string $directory = null)` — `$run` defaults to `getenv('PREFLOW_TEST_RUN')`; `$directory` defaults to the spec's snapshot dir.
  - `static bootstrap(): void` — sets `PREFLOW_TEST_RUN` (putenv) when absent and registers a shutdown function that removes the run's directory, only in the process that set it.
  - `enabled(): bool` — false without a run id or with `PREFLOW_TEST_SNAPSHOTS=0`.
  - `root(string $placeholder, string $directory): static` — placeholder like `'{siteTmp}'`; an empty directory string is ignored.
  - `ignore(string $path): static` — a path as `'{siteTmp}/error.log'`, excluded from key and restore.
  - `onRestore(\Closure $callback): static` — called (no arguments) after a hit's files are in place.
  - `step(string $name, callable $step): mixed`
  - Snapshots of this process: static, last 4 in memory (shared by all instances), the rest in files.

- [ ] **Step 1: Write the failing tests** (`SetupSnapshotsTest`, two temp dirs per test as roots, a counter `$runs` incremented by the probe step that writes `root/data/made.txt` and returns `'made'`):
  - `test_a_restored_step_leaves_what_the_real_step_left` — real step, then a test-like dirtying (extra file, changed file), new instance with a **new** temp root of equal pre-content: result `'made'`, `$runs === 1`, files under the new root identical to after the real step (extra file gone).
  - `test_roots_with_other_names_but_equal_content_hit` — `$runs === 1` across two differently named roots.
  - `test_another_environment_runs_the_step` — `putenv('PREFLOW_SNAPSHOT_PROBE=1')` before the second call: `$runs === 2`.
  - `test_another_file_runs_the_step` — a file written into the root first: `$runs === 2`.
  - `test_an_ignored_file_does_not_count` — `ignore('{root}/error.log')`, differing error.log: `$runs === 1`.
  - `test_on_restore_is_called_on_a_hit_only` — callback count 0 after the miss, 1 after the hit.
  - `test_a_failing_step_is_not_stored` — step throws `\RuntimeException`; `expectException` on first; second call runs the step again.
  - `test_an_unserializable_result_runs_every_time` — step returns `fn () => 1`; two calls, `$runs === 2`, each returns a `\Closure`.
  - `test_an_outcome_naming_the_real_root_is_not_stored` — step writes the root's real path into a file: `$runs === 2`.
  - `test_off_without_run_or_with_the_switch` — `new SetupSnapshots(run: '')` and `PREFLOW_TEST_SNAPSHOTS=0`: `enabled()` false, step runs each time.
  - `test_the_snapshot_is_read_by_another_process` — after a miss, run a child `php -r` (autoload, same run id/dir, same env) that calls `step()` on an equal root and prints the result and whether the probe ran: prints `made` and probe not run.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/testing/tests/Fast/SetupSnapshotsTest.php` — Expected: FAIL, class not found.
- [ ] **Step 3: Implement `SetupSnapshots`** following holz `SiteSnapshot.php` (state = serialized env with roots replaced by placeholders + file content hashes; key = `xxh128(name . "\0" . state)`; capture after the real step; restore = delete current files under roots (children first, ignored paths kept) then write the snapshot's; storage via temp + rename). No database code yet.
- [ ] **Step 4: Run** the test file — Expected: all pass. Then `vendor/bin/phpunit packages/testing` — Expected: OK.
- [ ] **Step 5: Commit** `feat(testing): SetupSnapshots: set-up steps run once per state, restored after`.

### Task 2: Databases in snapshots, `MysqlSnapshotDatabase`

**Files:**
- Create: `packages/testing/src/Fast/SnapshotDatabase.php`, `packages/testing/src/Fast/MysqlSnapshotDatabase.php`
- Modify: `packages/testing/src/Fast/SetupSnapshots.php`
- Test: `packages/testing/tests/Fast/MysqlSnapshotDatabaseTest.php` (skips unless `MysqlTestDatabase::configured()`; uses the configured test database)

**Interfaces:**
- Consumes: Task 1's `SetupSnapshots`.
- Produces:
  - `interface SnapshotDatabase { public function state(): array; public function capture(): array; public function restore(array $tables): void; public function empty(): void; }`
  - `final class MysqlSnapshotDatabase implements SnapshotDatabase` — `__construct(\Closure $pdo, string $database)`; `$pdo` returns a `\PDO`; every call checks `SELECT DATABASE()` equals `$database` (else `\LogicException`).
  - `SetupSnapshots::database(string $name, SnapshotDatabase $db): static`, `reset(): void` (all databases emptied lazily), `flush(): void` (writes pending restores/empties now). `step()` calls `flush()` before a real step; a hit sets the tables pending instead of writing them; the key of a following step uses the pending snapshot's recorded `state()` (holz's `tableStates`).

- [ ] **Step 1: Write the failing tests** — port holz `SiteSnapshotTest` database cases onto a probe step that `CREATE TABLE probe (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(20), note TEXT NULL, raw VARBINARY(8))` and inserts `('a', NULL, 0x00FF10), ('b', 'Zürich', '')`:
  - `test_a_restored_step_leaves_what_the_real_step_left` (extra rows, extra table, `ALTER ... MODIFY`, then restore: dump equal; next insert gets id 3)
  - `test_the_same_table_definition_is_restored_too`
  - `test_another_database_runs_the_step` (a table created before the step)
  - `test_reset_empties_lazily` (`reset()` then no DB access: tables still there; `flush()`: none)
  - `test_two_restored_steps_write_the_tables_once` — steps `a` then `b` both cached; on the second run spy (a `SnapshotDatabase` decorator counting `restore()` calls) sees exactly 1 restore after `flush()`.
  - `test_another_database_name_is_refused` — adapter given `'other_db'`: `\LogicException`.
- [ ] **Step 2: Run** (with `MYSQL_*` set from holz's `.env` test DB, see Global Constraints) — Expected: FAIL.
- [ ] **Step 3: Implement** the interface, the adapter (holz `restoreTables`/`tableStates`/`captureSnapshot` logic, one connection per call, `DELETE` + single-transaction `INSERT` chunks of 200, `AUTO_INCREMENT` only where moved) and the lazy database handling in `SetupSnapshots`.
- [ ] **Step 4: Run** the new tests and `vendor/bin/phpunit packages/testing` — Expected: OK (MySQL tests run when configured, skip otherwise).
- [ ] **Step 5: Commit** `feat(testing): snapshots of databases, MySQL adapter`.

### Task 3: `TestWorker`, docs, release notes

**Files:**
- Create: `packages/testing/src/Fast/TestWorker.php`
- Modify: `packages/testing/README.md` (new section "Fast suites"), `ROADMAP.md` (0.56 entry: what, why, measured timings filled in by Task 7), `packages/testing/composer.json` only if a requirement is missing
- Test: `packages/testing/tests/Fast/TestWorkerTest.php`

**Interfaces:**
- Produces: `final class TestWorker` — `static token(): ?int` (digits in `TEST_TOKEN`, else null), `static suffix(): string` (`''` / `'_' . token`), `static name(string $base): string`, `static dir(string $base): string` (`sys_get_temp_dir() . '/' . $base . suffix`, created), `static createMysqlDatabase(\PDO $server, string $name, string $user): void` (`CREATE DATABASE IF NOT EXISTS … utf8mb4 utf8mb4_unicode_ci`; on failure `\RuntimeException` naming the `GRANT ALL PRIVILEGES ON \`<base>\_%\`.* TO '<user>'@'localhost';` exactly as holz's message).

- [ ] **Step 1: Failing tests:** `test_without_a_token_names_are_unchanged` (`name('x') === 'x'`, `dir('x')` ends `/x` and exists), `test_a_worker_token_suffixes_names_and_dirs` (`TEST_TOKEN=3`: `x_3`), `test_a_non_numeric_token_is_ignored`, `test_create_mysql_database_names_the_grant_on_failure` (a PDO stub whose `exec` throws `PDOException`: message contains ``GRANT ALL PRIVILEGES ON `holz\_test\_%`.*``).
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** package tests — OK.
- [ ] **Step 5: README "Fast suites"** — the snapshot rule (what makes a state equal), effects-outside-roots rule (derived caches only), roots/ignore/onRestore, return values, databases and lazy flush, `bootstrap()`, `PREFLOW_TEST_SNAPSHOTS=0`, `TestWorker`, paratest + `composer test` / `test:serial` scripts (copy holz's composer.json scripts block).
- [ ] **Step 6: Commit** `feat(testing): TestWorker; docs for fast suites`.

**Controller step after Task 3 (not a subagent task):** full framework suite, merge to main, ROADMAP current state `v0.56.0 — <count> tests`, tag `v0.56.0`, push, wait for Packagist.

### Task 4: holz-objekte.org on the package

**Files (holz repo):**
- Delete: `tests/Support/SiteSnapshot.php`, `tests/Support/TestWorker.php`
- Modify: `tests/Support/SiteScaffold.php`, `tests/Support/SourceFixture.php`, `tests/Support/create-drafts.php`, `tests/Integration/BootTest.php`, `tests/Integration/ReviewMailTest.php`, `tests/bootstrap.php`, `tests/Integration/SiteSnapshotTest.php` (keep only the holz wiring: migrate+seed restored equals real, imageDir as a root), `composer.json` (`^0.56`), `tests/Unit/DeployConfigTest.php` (`'^0.56'`)

**Interfaces:** Consumes Tasks 1–3. `SiteScaffold` holds a `SetupSnapshots` built in `bootSite()` with roots `{siteTmp}` and, when the test uses `ImageFixtures`, `{imageDir}`; `ignore('{siteTmp}/error.log')`; `database('test', new MysqlSnapshotDatabase(fn () => $this->rawTestPdo(), TestWorker::name('holzobjekte_test')))`; `onRestore(fn () => $this->siteApp = null)`; `migrateAndApply()`/`seedSite()` call `step('migrate'|'seed', …)`; `app()`/`testPdo()` call `flush()`; `bootSite()` calls `reset()`. Databases named via `TestWorker::name()`, Folio cache via `TestWorker::dir('holzobjekte_folio_cache')` (unnumbered run: project `storage/cache` as today).

- [ ] **Step 1:** `composer update "preflow/*"` after the 0.56 release; rewire as above.
- [ ] **Step 2:** `composer test` — Expected: 955 tests OK (1 incomplete), time ≤ 3:05. Then `composer test:serial` — OK. (Nothing else may use the holz test DBs meanwhile.)
- [ ] **Step 3: Commit** `test: snapshots and workers from preflow/testing`, plus `build(deps): Preflow 0.56`.

### Task 5: BG Genius

**Files (bggenius-preflow):** `tests/Folio/BootsFolioApp.php`, `tests/bootstrap.php`, `composer.json` (paratest require-dev, scripts, `^0.56 || @dev`), `tests/ServerComposerTest.php` (`'^0.56'`), `deploy/server-composer.php` comment.

**Interfaces:** `folioApp()` builds a `SetupSnapshots` with root `{folioTmp}` (SQLite file inside), `ignore('{folioTmp}/error.log')`, `onRestore` nothing needed (app booted after); the schema load + `migrate` (and for staff `folio:extensions:apply`/`check`) become `step('schema-migrate')` / `step('staff-apply')`. `FOLIO_CACHE_PATH` per worker: `TestWorker::dir('bggenius-test-folio-cache')` when a token is set (keep today's per-run dir otherwise).

- [ ] **Step 1:** Time the suite before (`vendor/bin/phpunit`, record). **Step 2:** rewire; `composer test` and `composer test:serial` — Expected: all pass except `AiConfigTest` only while the owner's local `config/ai.php` override is present (check with `git stash push config/ai.php` → pass → `git stash pop`). Record times. **Step 3:** commit (never `config/ai.php`, `config/folio/extensions.json`).

### Task 6: smart-stack.works and smeyer.me

**Files (each repo):** `tests/Support/SiteScaffold.php`, `tests/bootstrap.php` (create if phpunit.xml points at vendor autoload: same as holz), `phpunit.xml`, `composer.json` (paratest, scripts, `^0.56 || @dev`), `tests/Unit/ServerComposerTest.php` (`'^0.56'`).

**Interfaces:** `bootSite()` holds a `SetupSnapshots` with root `{siteTmp}`, `ignore('{siteTmp}/error.log')`, `onRestore(fn () => $this->siteApp = null)`. Wrap the measured-expensive set-up (the fixture import in smart-stack; whatever a timing test shows in smeyer) as steps; a step whose result is an object returns an array of what callers use.

- [ ] **Step 1:** Timing test per site (as done in holz: time each set-up part, then delete the timing test). **Step 2:** rewire + paratest; `composer test`, `composer test:serial` — all pass. Record times. **Step 3:** commit per site.

### Task 7 (controller): roll-out

- Fill measured before/after times into the ROADMAP 0.56 entry (framework commit, push).
- Per site: push, back up (data / DB as in earlier deploys), deploy, verify `preflow/view v0.56.0` and pages 200 — owner granted deploy permission (memory `feedback_deploy_permission`).
