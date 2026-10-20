# Folio 4a/4d Follow-ups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Fix the "worth doing now" minors from the 4a (visual editor) and 4d (`folio:pull`) final reviews.

**Spec:** `docs/superpowers/specs/2026-10-19-folio-editor-experience-design.md` (4a, 4d).

## Global Constraints

- **Change scope:** only behaviour named below changes. Every fix is a test written first (RED), then the change (GREEN), then the package suite.
- **JS checks:** JS fixes are verified with string checks in `AdminJsTest` (no JS runner) and in the browser where behaviour is involved.

---

### Task 1: Visual editor follow-ups (`admin.js`)

- **1.1** The debug toolbar sits inside `<body>` with per-request timings, so the skeleton always differs. `cleaned()` removes `#preflow-debug-toolbar`.
  - Test: `AdminJsTest` asserts `'#preflow-debug-toolbar'`.
- **1.2** Escape after a lifted card moved: if an autosave fired while the card was in its moved position, the restored order is never saved. Fix: `lifted.moved` is set on every arrow move, and cancel calls `notifyChange` when it is true.
  - Test: string `lifted.moved`.
- **1.3** Matrix add, move and remove do not fire `change`, so autosave, the preview and the unsaved guard miss them. Fix: call `notifyChange(root)` after each.
  - Test: `AdminJsTest` asserts that `initMatrix` calls `notifyChange`.
- **1.4** The unsaved guard clears on `submit` before a `data-confirm` cancel. Fix: clear only when the submit was not prevented, checked after the event has finished.
  - Test: string `defaultPrevented` inside `initUnsavedGuard`.

### Task 2: Transfer follow-ups

- **2.1** Writes are unchecked when extracting (disk full) and in `ProcessRunner`. Fix: check the result of `fwrite` and throw `TransferException` on a short write.
  - Test: `TarTest::test_short_write_while_extracting_throws` copies an entry into a read-only stream.
- **2.2** An `ssh` value starting with `-` is read by ssh as an option. Fix: reject it with exit 1.
  - Test: `PullCommandTest::test_ssh_value_starting_with_dash_is_refused`.
- **2.3** An archive can place web-executable or server-config files under `media/` or `uploads/`. Fix: reject any path segment starting with `.` (`.htaccess`, `.user.ini`) and executable extensions: `php`, `phtml`, `phar`, `php3`–`php8`, `pht`, `phps`, `shtml`, `cgi`, `pl`, `py`, `sh`, `asp`, `aspx`, `jsp`.
  - Test: `ExportImportTest::test_import_rejects_executable_or_config_files_in_media`.
- **2.4** A failed export to a file, or a failed backup, leaves a partial file. Fix: unlink it on failure.
  - Tests:
    - `TransferCommandsTest::test_failed_export_leaves_no_partial_file`;
    - `test_failed_backup_leaves_no_partial_backup`, where local content with an id that cannot be exported makes the backup fail.
- **2.5** The pull's temp archive and the extracted copy may be readable by other users. Fix: create the temp file with mode 0600; the extract dir is already 0700.
  - Test: `PullCommandTest::test_incoming_archive_is_private`, where the fake runner checks the perms of the stream's path.

### Task 3: Docs

- **README:**
  - The remote `path` is home-relative, without `~`.
  - `php` is a single binary path.
  - Symlinked subdirectories inside media are skipped; a symlinked media root is followed.
- **Spec 4d:** describe the approach as built:
  - records go through the data layer, with per-file atomic writes;
  - there is no cross-table snapshot, and the next pull heals a torn read.

### Task 4: Release

- Run the full suite.
- Merge, push and tag `v0.16.1`, then wait for the split and Packagist.
- Update smeyer.me's lock, run its suite, then push and deploy.
- Browser-check one block edit in local debug mode (in-place patch).
