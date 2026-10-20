# Folio 4d `folio:pull` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A single command, `php preflow folio:pull production`, brings live content (Folio records and media) into the local copy over SSH, with a backup first and a dry run. `folio:export` and `folio:import` also work alone, as content backup and restore.

**Architecture:**
- **Archive:** a portable tar archive with a manifest.
- **Records:** written per table *through the data layer* (`data/{table}/{id}.json`), so JSON, SQLite and MySQL types export the same way.
- **Media:** asset and legacy-upload files are stored as `media/…` and `uploads/…`.
- **Import:** unpacks into a temporary directory, validates every path, computes the differences, writes a backup, then applies the differences.
- **Pull:** runs the export remotely through a `CommandRunner` (`proc_open` with an argv array, so no local shell), then imports.

**Tech Stack:** PHP 8.4, the devtools `CommandInterface`, PHPUnit 11. The zlib extension is used only for `.gz` files.

**Spec:** `docs/superpowers/specs/2026-10-19-folio-editor-experience-design.md`, section 4d (approved 2026-10-19).

## Global Constraints

- **Credentials:** none in config. SSH uses the user's agent and `~/.ssh/config`, and never passes `StrictHostKeyChecking=no`, so a changed host key aborts.
- **Content:** Folio types' tables, their `__published` and `__revisions` shadows, `folio_assets`, the media directory and legacy uploads.
- **Never content:** code, `.env`, cache, logs, sessions, `user_tokens`, or any other non-Folio table.
- **No push:** there is no `folio:push`.
- **Pure output:** on `--stdout` the export writes nothing but the archive to stdout. Messages go to stderr.
- **Local writes:** before any local change, a backup goes to `storage/backups/pull-YYYYmmdd-HHMMSS.tar.gz` (`import-…` for import). A failed remote export, or an invalid archive, changes nothing locally.
- **Commands:** they follow the existing pattern: the `BootsApplication` trait, an optional app factory, manual argument parsing, and exit code 0 or 1. They also take injectable `$stdout`/`$stderr` resources for tests.

## Review Focus

1. A malicious or corrupt archive (paths with `../`, absolute paths, symlink or device entries, a manifest naming a non-Folio table, a truncated stream) must abort with nothing written locally.
2. A record that exists locally but not in the archive is removed on import; a local-only table outside Folio (`user_tokens`) is untouched.
3. A partially written JSON record must never be exported as broken JSON. Writes are atomic, and the export reads only whole files.
4. The remote command exits non-zero, or prints an SSH error, with partial stdout: abort, delete the temp file, keep local content and write no backup.
5. `--only=media` must not touch records, and `--only=data` must not touch files.

---

### Task 1: Atomic JSON writes

**Files:** `packages/data/src/Driver/JsonFileDriver.php`; test `packages/data/tests/Driver/JsonFileDriverTest.php` (extend).

**Interfaces:**
- `save()` writes `{id}.json.tmp-{random}` in the same directory, then calls `rename()` onto `{id}.json`.
- `findMany()` keeps globbing `*.json`, so temp files are never read.

- [ ] **Step 1:** Test `test_save_is_atomic_and_leaves_no_temp_files`:
  - after 20 saves of the same id, the directory holds exactly one file;
  - its JSON decodes to the last data;
  - no `*.tmp-*` file is left.

  Plus `test_find_many_ignores_temp_files`: a stray `x.json.tmp-1` is not returned.
- [ ] **Step 2:** Run. Expected: the second test FAILS if the glob matches it. If both pass, keep them as regression guards and note it in the ledger.
- [ ] **Step 3:** Implement. Write the temp file with `LOCK_EX`, then `rename`. Clean the temp file up on failure.
- [ ] **Step 4:** Data suite PASS.
- [ ] **Step 5:** Commit `feat(data): atomic JSON record writes`.

### Task 2: Tar stream writer and reader

**Files:**
- Create: `packages/folio/src/Transfer/TarWriter.php`, `packages/folio/src/Transfer/TarReader.php`
- Test: `packages/folio/tests/Transfer/TarTest.php`

**Interfaces:**
- `TarWriter::__construct($stream)` takes a writable resource.
  - `addFile(string $name, string $sourcePath)` and `addString(string $name, string $bytes)` write ustar regular-file entries. Names over 100 bytes are split into prefix and name; paths over 255 bytes throw.
  - `close()` writes the two zero blocks.
- `TarReader::__construct($stream)` takes a readable resource.
  - `entries(): \Generator` yields `[string $name, int $size, \Closure $copyTo]`, where `$copyTo` takes a path or stream.
  - It throws `TransferException` on:
    - a checksum mismatch;
    - a truncated entry;
    - a type other than a regular file (`'0'` or `"\0"`) or a directory (`'5'`, skipped);
    - an unsafe name: absolute, `..` segments, a backslash, or a NUL.
- `TransferException extends \RuntimeException`.

- [ ] **Step 1:** Tests:
  - `test_round_trip_names_and_bytes`: includes a 180-byte path and binary bytes.
  - `test_rejects_unsafe_names`: a data provider covering `../x`, `/etc/x`, `a/../../x` and `a\\b`, each written raw.
  - `test_rejects_symlink_entries`.
  - `test_rejects_truncated_stream`.
  - `test_rejects_bad_checksum`.
- [ ] **Step 2:** Run. Expected: FAIL (classes are missing).
- [ ] **Step 3:** Implement. Use 512-byte blocks, octal size and checksum fields, and `ustar\0` with `00`.
- [ ] **Step 4:** Folio suite PASS.
- [ ] **Step 5:** Commit `feat(folio): tar stream writer and reader for content transfer`.

### Task 3: Content set, export, import

**Files:**
- Create: `packages/folio/src/Transfer/ContentSet.php`, `Exporter.php`, `Importer.php`, `ImportPlan.php`
- Test: `packages/folio/tests/Transfer/ExportImportTest.php`

**Interfaces:**
- `ContentSet::fromApp(Application $app): ContentSet` builds the set from the `TypeCatalog`:
  - for each type, its `TypeDefinition` (`table`, `storage`);
  - for publishing types, the shadow definitions (`ShadowTypes`);
  - the `_asset` definition (`folio_assets`);
  - media dir = `folio.assets.dir ?? public/media`;
  - uploads dir = `folio.uploads_path ?? storage/uploads`.
- `->tables(): array<string, TypeDefinition>`, keyed by table; `->mediaDir(): ?string`; `->uploadsDir(): ?string`.
- `Exporter::__construct(ContentSet $set, DataManager $dm)`.
  - `->export($stream, string $only = 'all'): array` returns a summary (counts per table, files, bytes).
  - It writes `folio-export.json` first:
    ```
    {format: 1, created_at, tables: {table: count}, media: count, uploads: count}
    ```
    Then `data/{table}/{id}.json` (the raw stored row, `JSON_PRETTY_PRINT`), then `media/{rel}` and `uploads/{rel}`.
  - Files are walked recursively, skipping dotfiles and symlinks, sorted.
- `Importer::__construct(ContentSet $set, DataManager $dm)`.
  - `->read($stream, string $only = 'all'): ImportPlan` extracts into a temp dir and validates. Validation fails when:
    - the manifest is missing, or its format is not 1;
    - a table is not in `$set->tables()`;
    - an entry is outside `data/`, `media/` or `uploads/`;
    - an id is not `[A-Za-z0-9._-]+`.

    It then diffs against the local content: per table, the ids added, changed (canonical JSON compare) and removed; for files, added, changed (size + sha1) and removed.
  - `->apply(ImportPlan $plan): void`:
    - records: rows are written with the driver's save for the table and storage (`DataManager::resolveDriver($def->storage)->save($table, $id, $row)`), and removed ids are deleted;
    - files: copied by temp + rename, and removed ones unlinked;
    - empty directories are removed.
  - `ImportPlan` exposes `summary(): array`, `isEmpty(): bool` and `cleanup()` (deletes the temp dir).

- [ ] **Step 1:** Tests, using a scaffold app (PublishingScaffold) with a publishing type, an asset and a non-Folio `user_tokens` row:
  - `test_round_trip_restores_records_shadows_assets_and_files`: export, wipe locally, import; everything is identical.
  - `test_import_removes_local_only_records_and_files_but_not_other_tables`.
  - `test_import_rejects_unknown_table_and_writes_nothing`.
  - `test_import_rejects_path_outside_content_and_writes_nothing`.
  - `test_only_media_leaves_records_and_only_data_leaves_files`.
  - `test_plan_summary_counts_added_changed_removed`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement. Confirm the driver method names against `packages/data/src/Driver` (`findMany`, `save`, `delete`), and use what exists.
- [ ] **Step 4:** Folio suite PASS.
- [ ] **Step 5:** Commit `feat(folio): content export and import through the data layer`.

### Task 4: `folio:export` and `folio:import`

**Files:**
- Create: `packages/folio/src/Console/ExportCommand.php`, `ImportCommand.php`
- Modify: `FolioCommands::all()`
- Test: `packages/folio/tests/Console/TransferCommandsTest.php`

**Interfaces:**
- **`folio:export [--stdout | <file>] [--only=data|media]`:**
  - writes to the file, gzip-compressed when the name ends in `.gz`, otherwise to stdout;
  - the summary goes to stderr;
  - exit 0.
- **`folio:import <file> [--dry-run] [--only=data|media] [--yes]`:**
  1. reads the file (`.gz` aware);
  2. prints the plan summary;
  3. stops after the summary on `--dry-run`;
  4. otherwise writes the backup `storage/backups/import-YYYYmmdd-HHMMSS.tar.gz` (a full local export);
  5. applies the plan and prints `Done. Run "php preflow cache:clear" if pages look stale.`
- The constructor is `(?\Closure $appFactory = null, $stdout = null, $stderr = null)`.
- A shared `Transfer\Backups::write(Application $app, string $prefix): string` returns the backup path.

- [ ] **Step 1:** Tests:
  - `test_export_to_stdout_is_a_valid_archive_and_nothing_else`: the captured stdout parses with `TarReader`, and the first entry is `folio-export.json`.
  - `test_export_to_gz_file`.
  - `test_import_dry_run_writes_nothing_and_no_backup`.
  - `test_import_writes_backup_before_applying`: the backup exists, and importing the backup restores the pre-import state.
  - `test_import_bad_file_exits_1_and_changes_nothing`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** PASS.
- [ ] **Step 5:** Commit `feat(folio): folio:export and folio:import commands`.

### Task 5: `folio:pull`

**Files:**
- Create:
  - `packages/folio/src/Transfer/CommandRunner.php` (interface)
  - `packages/folio/src/Transfer/ProcessRunner.php`
  - `packages/folio/src/Console/PullCommand.php`
- Modify: `FolioCommands::all()`
- Test: `packages/folio/tests/Console/PullCommandTest.php` with a `FakeRunner`

**Interfaces:**
- `CommandRunner::run(array $argv, $stdout, $stderr): int` streams the child's stdout into `$stdout` and its stderr into `$stderr`, and returns the exit code. `ProcessRunner` uses `proc_open($argv, …)` with an array command (no shell).
- **Config:** `folio.remotes.{name}` = `['ssh' => 'user@host' | 'host-alias', 'port' => ?int, 'path' => '/abs/or/home-relative', 'php' => 'php' (default)]`.
- **Remote argv:**
  ```
  ['ssh', '-o', 'BatchMode=yes', (-p port), $ssh, 'cd ' . escapeshellarg($path) . ' && ' . escapeshellarg($php) . ' preflow folio:export --stdout' . ($only ? ' --only=' . $only : '')]
  ```
  The remote side does go through a shell, so `path` and `php` are quoted.
- **`folio:pull <remote> [--dry-run] [--only=data|media]`:**
  1. Stream the remote export into `storage/backups/incoming-….tar`.
  2. If the exit code is not 0, delete the file and exit 1, printing the remote stderr.
  3. `Importer->read`, then print the summary.
  4. Dry run: clean up and exit 0.
  5. Otherwise write the backup `pull-…`, apply, clean up, and print the cache hint.
- `PullCommand` takes `(?\Closure $appFactory = null, ?CommandRunner $runner = null, $stdout = null, $stderr = null)`.

- [ ] **Step 1:** Tests (the `FakeRunner` asserts the argv and writes a locally produced export to `$stdout`):
  - `test_pull_builds_ssh_command_without_disabling_host_keys`: the argv contains `BatchMode=yes` and no `StrictHostKeyChecking`, and the path is quoted.
  - `test_pull_applies_remote_content_after_backup`.
  - `test_pull_dry_run_changes_nothing`.
  - `test_failed_remote_aborts_without_backup_or_changes`: the fake exits 255 with partial stdout.
  - `test_unknown_remote_exits_1`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** PASS. Then run the full framework suite.
- [ ] **Step 5:** Commit `feat(folio): folio:pull live content over SSH`. Update the Folio README section for the commands.

### Task 6: Release and the smeyer.me remote

**Files:**
- smeyer.me: `config/folio.php` (`'remotes' => ['production' => ['ssh' => 'giantbits.com', 'path' => 'public_html/smeyer.me/current', 'php' => '/usr/local/bin/php84']]`)
- smeyer.me: `docs/deploy.md` ("Content back to local" now uses `folio:pull`)
- framework tag `v0.16.0`

- [ ] **Step 1:**
  1. Run the full framework suite.
  2. Merge, push and tag `v0.16.0`, then wait for the split and for Packagist.
  3. In smeyer.me: constraint `^0.16 || @dev`, `composer update --lock`, the suite, then commit, push and deploy (the server needs `folio:export`).
- [ ] **Step 2:** Real check against production, read-only remotely, into a throwaway local copy (`DATA_PATH`/`MEDIA_PATH` temp dirs):
  - `php preflow folio:pull production --dry-run` lists the live records;
  - then a real pull writes a backup and the content;
  - the local site renders the live copy.
