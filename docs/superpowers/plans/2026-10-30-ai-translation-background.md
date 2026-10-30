# AI translation in the background — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A record's Translate queues a job that a cron-run worker (or an open status page) processes in short, leased steps; results land as drafts with "not checked" marks, publishing clears them, and a dashboard widget lists what waits for review.

**Architecture:** Queue, lease, worker, review index and the two console commands live in `preflow/i18n` (`Preflow\I18n\Machine`), on top of the 0.47 `JobRunner`/`TextSource`. Folio adapts: a queue endpoint and dialog mode, the run screen as status page, publish clearing marks, the review index kept current from its save paths, and the dashboard widget. BG Genius' flow screen queues with prefix `flow`.

**Tech Stack:** PHP 8.5, PHPUnit 11, Twig, plain JS (node:test for Folio and BG JS tests).

**Spec:** `docs/superpowers/specs/2026-10-30-ai-translation-background-design.md` (builds on `docs/superpowers/specs/2026-10-30-ai-translation-design.md`).

## Global Constraints

- Decoupling (owner rule): queue, lease, worker and review index are in `preflow/i18n` and work without Folio; Folio only adapts.
- AI off: no button, no queue endpoint (404), the worker exits 0 at once, no widget.
- cyon: one worker run must end within the cron interval; default budget 50 s, checked between records; a started record always finishes.
- Lease per record (collection + id), expiry 600 s; a driver that finds the record claimed skips it, never waits.
- Job state `queued` = started, waiting for a driver; `running` = a driver works on it now. 0.47 job files (no new fields, state `running`/`planned`/`stopped`/`done`) load unchanged.
- Prefix semantics as 0.47 `suggest`: a unit matches when its id equals the prefix or starts with `prefix.`.
- Types without publishing: the go-live confirmation is given when queuing and stored on the job; a step refuses to write a job of such a type without it.
- Publishing clears `_machine_translated` only for the locales in the record's "Visible in" (`availableLocales()`), via every publish path (form, bulk, `folio:publish`, scheduler).
- `publishing.checked_translations`, `Publisher::requireCheckedTranslations()` and `Publisher::uncheckedNotice()` are removed; a config still setting the key is ignored without error.
- "No worker" = no worker run recorded in the last 300 s while jobs are queued.
- New UI strings in Folio's `lang/en/folio.php` and `lang/de/folio.php` (German proper, "du"-free admin tone as existing keys); `lang/fr/folio.php` gets the English text, as the 0.47 keys did.
- No new SQL columns. Release notes: sites with AI on need the cron line `* * * * * cd <app> && php vendor/bin/preflow translate:work`.

## Review Focus

1. Two worker processes overlapping (cron fires while the last run is still in a long record): the second must skip the claimed record and take another, never translate the same record twice — test in Task 2.
2. AI switched off or key removed while jobs are queued: the worker exits 0, jobs stay `queued` (not failed), and work resumes when AI is back — test in Task 3.
3. A queued record deleted before the worker reaches it: that item ends `failed` with a message, the job continues with the others — test in Task 2.
4. Publishing for an English fix while German is not ticked in "Visible in": German marks stay, the record stays in the review index — test in Task 5.
5. A 0.47 job file (state `running`, no prefix/confirmation fields) after upgrade: loads, is continued by the worker, and a live-type 0.47 job that was already running is not blocked by the new confirmation rule — test in Task 1.

---

### Task 1: Job fields, record lease

**Files:**
- Modify: `packages/i18n/src/Machine/Job.php`
- Create: `packages/i18n/src/Machine/RecordLease.php`, `packages/i18n/src/Machine/FileRecordLease.php`
- Test: `packages/i18n/tests/Machine/FileJobStoreTest.php`, create `packages/i18n/tests/Machine/FileRecordLeaseTest.php`

**Interfaces:**
- Produces:
  - `Job` new constructor-promoted properties after `$costUnknown`: `public ?string $prefix = null`, `public bool $confirmedLive = false`, `public ?string $queuedBy = null`, `public ?string $queuedAt = null` (ISO 8601). State docblock: `'planned' | 'queued' | 'running' | 'stopped' | 'done'`. `toArray()` keys `prefix`, `confirmed_live`, `queued_by`, `queued_at`; `fromArray()` defaults them (null/false). A 0.47 file with `state: running` and no `confirmed_live` loads with `confirmedLive = true` (it was started under the 0.47 rule, which already confirmed).
  - `interface RecordLease { public function claim(string $collection, string $id, int $ttlSeconds = 600): bool; public function release(string $collection, string $id): void; }`
  - `final class FileRecordLease implements RecordLease { public function __construct(string $dir, ?\Closure $now = null) }` — one file per record named by `sha1($collection . "\0" . $id)`, created atomically (`fopen(..., 'x')`); an existing file older than its TTL (expiry stored in the file) is taken over.

- [ ] **Step 1: Write the failing tests**
  - `FileJobStoreTest::test_new_fields_round_trip` — save a job with `prefix: 'flow'`, `confirmedLive: true`, `queuedBy: 'anna'`, `queuedAt: '2026-10-30T12:00:00+00:00'`, state `queued`; `get()` returns the same values.
  - `FileJobStoreTest::test_a_047_running_job_loads_as_confirmed` — write the JSON of a 0.47 job (state `running`, no new keys) to the store dir; `get()` gives `state === 'running'`, `prefix === null`, `confirmedLive === true`.
  - `FileRecordLeaseTest::test_claim_is_exclusive_until_released` — `claim('game','g1')` true, second `claim('game','g1')` false, `claim('game','g2')` true, after `release('game','g1')` claim true again.
  - `FileRecordLeaseTest::test_an_expired_claim_is_taken_over` — with a `$now` closure: claim at t, claim at t+599 false, at t+601 true.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/i18n/tests/Machine/FileJobStoreTest.php packages/i18n/tests/Machine/FileRecordLeaseTest.php` — expect failures (unknown properties / class).
- [ ] **Step 3: Implement** the Job fields and the lease as specified.
- [ ] **Step 4: Run** the same command — PASS.
- [ ] **Step 5: Commit** `feat(i18n): job prefix, go-live confirmation and queue fields; per-record lease`

### Task 2: JobRunner queues, leases and filters by prefix

**Files:**
- Modify: `packages/i18n/src/Machine/JobRunner.php`, `packages/i18n/src/Machine/MachineServiceProvider.php`
- Test: `packages/i18n/tests/Machine/JobRunnerTest.php`

**Interfaces:**
- Consumes: Task 1 (`Job` fields, `RecordLease`).
- Produces:
  - Constructor gains `private readonly RecordLease $lease` (last parameter; the provider binds `RecordLease` to `FileRecordLease(<storage>/leases)`).
  - `plan(string $collection, array $locales, Scope $scope, ?array $ids = null, ?string $note = null, ?string $prefix = null): Job` — the estimate counts only units matching the prefix.
  - `start(string $jobId, bool $confirmLive = false, ?string $by = null): Job` — planned/stopped/running → `queued`; sets `queuedAt` (now) and `queuedBy` when given; `confirmLive` true sets `confirmedLive` (never unsets it).
  - `step(string $jobId, int $maxItems = 1): Job` — takes pending items in order, skipping any whose record it cannot claim; sets `running` while working and back to `queued` (or `done`) when the step ends; releases each claim in `finally`. A job of a source that `writesLive()` without `confirmedLive` is set `stopped` with message `'Confirm that the translations go live before starting.'` and nothing is written. Units are filtered by `$job->prefix` (Global Constraints semantics) before translating.
  - `pendingFor(string $collection, string $id, string $locale, ?string $prefix = null): ?Job` — a `queued` or `running` job (among `RECENT_JOBS`) whose items hold `$id` as pending, whose locales include `$locale` and whose prefix equals `$prefix`.
  - `active(int $max = 10): list<Job>` — queued, running and stopped-with-message jobs, newest first.
  - `unfinished()` unchanged in meaning (not done).

- [ ] **Step 1: Write the failing tests** (in `JobRunnerTest`, using the existing kit's array source and fake client):
  - `test_start_queues_and_records_who` — `start($id, by: 'anna')` → `state === 'queued'`, `queuedBy === 'anna'`, `queuedAt` not null.
  - `test_step_returns_to_queued_while_items_remain` — two items, `step(…, 1)` → `state === 'queued'`, one item done.
  - `test_a_claimed_record_is_skipped_not_waited_for` (Review Focus 1) — claim `('posts','a')` on the same lease; `step` processes `b`, leaves `a` pending, and the fake client got requests only for `b`'s texts.
  - `test_the_claim_is_released_after_a_step_even_on_error` — the source throws in `write()`; afterwards `$lease->claim('posts','a')` is true.
  - `test_prefix_limits_units_and_estimate` — units `title`, `flow.a`, `flow.b`; `plan(…, prefix: 'flow')` estimate units 2; after a step only `flow.a`, `flow.b` were sent and written.
  - `test_live_source_without_confirmation_writes_nothing` — `writesLive()` true, `start($id)` then `step` → `stopped`, message as specified, source untouched; with `start($id, confirmLive: true)` the step writes.
  - `test_a_deleted_record_fails_only_its_item` (Review Focus 3) — source `units()` throws for `a`; `a` ends `failed` with the message, `b` done, job `done`.
  - `test_pending_for_finds_the_queued_job` — queued job for `('posts', ['de'], ids ['a'])`: `pendingFor('posts','a','de')` is it; `pendingFor('posts','a','fr')` null; `pendingFor('posts','a','de','flow')` null.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/i18n/tests/Machine/JobRunnerTest.php` — new tests FAIL.
- [ ] **Step 3: Implement** as in Interfaces; update existing tests that assert `running` after `start()` to `queued`.
- [ ] **Step 4: Run** `vendor/bin/phpunit packages/i18n/tests` — PASS.
- [ ] **Step 5: Commit** `feat(i18n): jobs queue, steps claim their record, prefix limits a job`

### Task 3: Worker and `translate:work`

**Files:**
- Create: `packages/i18n/src/Machine/TranslationWorker.php`, `packages/i18n/src/Machine/WorkReport.php`, `packages/i18n/src/Console/TranslateWorkCommand.php`
- Modify: `packages/i18n/src/Machine/MachineServiceProvider.php`, `packages/i18n/composer.json` (`extra.preflow.commands`), `packages/i18n/README.md`
- Test: create `packages/i18n/tests/Machine/TranslationWorkerTest.php`, `packages/i18n/tests/Console/TranslateWorkCommandTest.php`

**Interfaces:**
- Consumes: Task 2 (`JobRunner::step/active`), `JobStore::recent`, `Preflow\Ai\Ai::enabled()`.
- Produces:
  - `final class TranslationWorker { public function __construct(JobRunner $runner, JobStore $jobs, Ai $ai, string $stateFile, ?\Closure $now = null); public function work(int $seconds = 50, ?\Closure $log = null): WorkReport; public function lastRun(): ?\DateTimeImmutable; }`
    - `work()` writes the heartbeat (`{"last_run": ISO}` to `$stateFile`) first, returns at once when AI is off, then steps queued/running jobs oldest first (one record per step) until no job made progress or `$seconds` have passed (checked between steps). `AiUnavailable` stops that job (as `step` does) and ends the run. `$log` gets one line per record: `"{collection} {id} {locales}: {status}"`.
  - `final readonly class WorkReport { public function __construct(public int $records, public float $costUsd, public bool $costUnknown, public ?string $stoppedBecause) }`
  - `TranslateWorkCommand` name `translate:work`, args `[--seconds=N]` (default 50), prints the log lines and `"{records} records, {cost}"`; exit 0, also when AI is off (prints nothing then). Follows `TranslationsCheckCommand`'s app-factory constructor pattern.
  - Provider binds `TranslationWorker` with `<storage>/worker.json`.

- [ ] **Step 1: Write the failing tests**
  - `TranslationWorkerTest::test_works_queued_jobs_oldest_first_within_budget` — two queued jobs with 2 records each, a `$now` advancing 30 s per step, `work(50)` processes 2 records of the older job first and stops.
  - `test_ai_off_leaves_jobs_queued` (Review Focus 2) — AI disabled: `work()` returns `records 0`, jobs still `queued`, heartbeat written.
  - `test_unavailable_stops_that_job_and_ends_the_run` — fake client throwing `AiUnavailable`: first job `stopped` with message, second job untouched, report `stoppedBecause` set.
  - `test_continues_a_047_running_job` (Review Focus 5) — a job saved in 0.47 shape (state `running`) is processed.
  - `test_last_run_reads_the_heartbeat` — after `work()`, `lastRun()` equals the `$now` time.
  - `TranslateWorkCommandTest::test_prints_records_and_cost` and `test_ai_off_exits_zero_silently`.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/i18n/tests/Machine/TranslationWorkerTest.php packages/i18n/tests/Console/TranslateWorkCommandTest.php` — FAIL.
- [ ] **Step 3: Implement**; README section "Background translation" with the cron line from Global Constraints.
- [ ] **Step 4: Run** `vendor/bin/phpunit packages/i18n/tests` — PASS.
- [ ] **Step 5: Commit** `feat(i18n): translation worker and translate:work for cron`

### Task 4: Review index (core)

**Files:**
- Create: `packages/i18n/src/Machine/ReviewIndex.php`, `packages/i18n/src/Machine/FileReviewIndex.php`, `packages/i18n/src/Machine/ReviewEntry.php`
- Modify: `packages/i18n/src/Machine/MachineServiceProvider.php`
- Test: create `packages/i18n/tests/Machine/FileReviewIndexTest.php`

**Interfaces:**
- Produces:
  - `interface ReviewIndex { public function put(string $collection, string $id, string $title, array $counts): void; public function forget(string $collection, string $id): void; /** @return list<ReviewEntry> */ public function entries(): array; public function replaceAll(array $entries): void; }` — `$counts` is `array<string locale, int>`; `put()` with all counts 0 (or `[]`) forgets.
  - `final readonly class ReviewEntry { public function __construct(public string $collection, public string $id, public string $title, public array $counts, public string $updatedAt) }`
  - `FileReviewIndex(string $file)` — JSON at `<storage>/review.json`, written via temp file + rename; `entries()` newest `updatedAt` first.
- [ ] **Step 1: Tests** `test_put_and_entries`, `test_zero_counts_forget`, `test_forget`, `test_replace_all`, `test_missing_or_broken_file_is_empty`.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/i18n/tests/Machine/FileReviewIndexTest.php` — FAIL.
- [ ] **Step 3: Implement**; provider binds `ReviewIndex` to `FileReviewIndex(<storage>/review.json)`.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat(i18n): review index of records with unchecked machine translations`

### Task 5: Folio publish clears marks; index kept current; warning removed

**Files:**
- Create: `packages/folio/src/Content/ReviewIndexUpdater.php`, `packages/folio/src/Console/ReviewIndexCommand.php`
- Modify: `packages/folio/src/Publishing/Publisher.php`, `packages/folio/src/Content/TranslationStatus.php`, `packages/folio/src/FolioServiceProvider.php`, `packages/folio/src/Http/AdminController.php` (publish notice at ~1461, non-publishing save and delete paths), `packages/folio/src/Console/PublishCommand.php`, `packages/folio/src/Content/FolioTextSource.php` (non-publishing write path), `packages/folio/composer.json` (command), `packages/folio/README.md`
- Test: `packages/folio/tests/Publishing/PublisherTest.php`, `packages/folio/tests/Integration/PublishingAppTest.php`, create `packages/folio/tests/Content/ReviewIndexUpdaterTest.php`

**Interfaces:**
- Consumes: Task 4 `ReviewIndex`.
- Produces:
  - `TranslationStatus::clearLocales(DynamicRecord $record, array $locales): DynamicRecord` — drops the `_machine_translated` entries of those locales.
  - `final class ReviewIndexUpdater { public function __construct(ReviewIndex $index, TranslationStatus $status, Closure $title /* fn(DynamicRecord): string */); public function refresh(DynamicRecord $record): void; public function forget(string $type, string $id): void; }` — counts `unchecked()` per non-default locale of the type.
  - `Publisher` constructor: remove `$checkedTranslations`; add `private readonly ?ReviewIndexUpdater $review = null` (last). `publish()` clears marks for `$working->availableLocales()` before the version comparison, then refreshes the index; `saveDraft()` refreshes after writing. `requireCheckedTranslations()` and `uncheckedNotice()` are deleted with their callers and strings (`folio.publish.unchecked`, `folio.publish.unchecked_warning`).
  - `ReviewIndexCommand` name `folio:review-index`: rebuilds the index from all records of all types with translatable fields (`ReviewIndex::replaceAll`), prints the count.
- [ ] **Step 1: Write the failing tests**
  - `PublisherTest::test_publish_clears_marks_of_visible_locales` — working copy with marks for `de` and `fr`, `_locales` = `['en','de']`; after `publish()` the stored working copy and snapshot have no `de` marks and keep the `fr` ones (Review Focus 4 is the `fr` half).
  - `PublisherTest::test_publish_with_marks_keeps_the_index_entry_for_hidden_locales` — with a `FileReviewIndex`, after publish the entry has `counts === ['fr' => n]`.
  - `PublisherTest::test_save_draft_refreshes_the_index` — saving a draft with marks puts an entry; saving it after the text was edited (fingerprint differs) forgets it.
  - `PublishingAppTest`: replace the checked_translations/notice tests with `test_publish_has_no_unchecked_warning_and_ignores_the_old_setting` — config `'checked_translations' => true` still loads; publishing a record with marks succeeds without a notice.
  - `ReviewIndexUpdaterTest::test_rebuild_command_finds_marked_records`.
  - Deleting a record forgets it (AdminController delete) — assert in `PublishingAppTest::test_delete_forgets_review_entry`.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/folio/tests/Publishing packages/folio/tests/Integration/PublishingAppTest.php packages/folio/tests/Content/ReviewIndexUpdaterTest.php` — FAIL.
- [ ] **Step 3: Implement**; remove tests of the deleted behaviour; README: publishing is the check, the removed setting, `folio:review-index`.
- [ ] **Step 4: Run** `vendor/bin/phpunit --testsuite Folio` — PASS.
- [ ] **Step 5: Commit** `feat(folio): publishing clears the marks of visible languages; review index kept current`

### Task 6: Record Translate queues

**Files:**
- Modify: `packages/folio/src/Http/AiTranslateController.php`, `packages/folio/src/Routing/FolioRoutes.php`, `packages/folio/src/Http/AdminController.php` (form data ~1160-1200), `packages/folio/templates/admin/partials/ai-translate-dialog.twig`, `packages/folio/templates/admin/form.twig`, `packages/folio/assets/ai-translate.js`, `packages/folio/src/Content/DraftRecordFactory.php` (remove `_ai_unchecked`/`_ai_checked` handling), `packages/folio/lang/{en,de,fr}/folio.php`
- Test: `packages/folio/tests/Http/AiTranslateControllerTest.php`, `packages/folio/tests/Http/AiTranslateFormTest.php`, `packages/folio/tests/js/ai-translate.test.mjs`

**Interfaces:**
- Consumes: Task 2 (`plan` with prefix, `start(confirmLive, by)`, `pendingFor`), Task 3 (`TranslationWorker::lastRun`).
- Produces:
  - Route `POST {prefix}/{type}/{id}/_ai/queue` → `AiTranslateController::queue()`. Body: `locale`, `scope`, `note?`, `prefix?`, `confirm_live?`. Same checks as `units()` (404 AI off/unknown, 403, 422). Answers JSON `{job: string, state: string, existing: bool, message: string, worker: bool}`: a pending job from `pendingFor()` is returned with `existing: true` and not duplicated; otherwise `plan(type, [locale], scope, [id], note, prefix)` then `start(job, confirmLive, by: current user's name)`. A live type without `confirm_live=1` answers 422 with `folio.ai_queue.confirm_live`. `worker` is false when `lastRun()` is null or older than 300 s.
  - `suggest()` stays (core API and BG, until Task 9), but the record form no longer calls it.
  - Dialog: `ai_translate.queue_url` set → the dialog's confirm button reads `folio.ai_queue.button`, posts to it, and shows `message` (plus the cron hint `folio.ai_queue.no_worker` when `worker` is false); no form filling, no review rows. For live types the dialog shows a go-live checkbox (`confirm_live`).
  - Form: above the language bar, one line per pending job of the record (`pendingFor` for each language): `folio.ai_queue.pending` with language and since-time.
  - `DraftRecordFactory`: `_ai_unchecked[]`/`_ai_checked[]` are no longer read (posted values ignored).
- [ ] **Step 1: Write the failing tests**
  - `AiTranslateControllerTest::test_queue_plans_and_queues_one_record` — 200, `state === 'queued'`, job items `['p1']`, locales `['de']`, `queuedBy` set, nothing written to the record, no AI request made.
  - `test_queue_twice_returns_the_pending_job` — second call `existing: true`, same `job`, one job in the store.
  - `test_queue_live_type_needs_confirmation` — non-publishing type: 422 without `confirm_live`, 200 with it and `confirmedLive` true.
  - `test_queue_with_prefix` — `prefix: 'sections'` stored on the job.
  - `test_queue_404_when_ai_off`, `test_queue_403_without_permission`.
  - `test_queue_reports_no_worker` — no heartbeat: `worker === false`.
  - `AiTranslateFormTest::test_form_shows_pending_translation` — queued job for `p1`/`de` → the form HTML contains the pending line.
  - `AiTranslateFormTest::test_posted_ai_paths_are_ignored` — posting `_ai_unchecked[]=title` saves no mark.
  - `ai-translate.test.mjs`: `queue mode posts and shows the message without filling inputs`.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/folio/tests/Http/AiTranslateControllerTest.php packages/folio/tests/Http/AiTranslateFormTest.php && node --test packages/folio/tests/js` — FAIL.
- [ ] **Step 3: Implement**; remove form-fill tests for records that no longer apply (labels/alt fill tests stay).
- [ ] **Step 4: Run** `vendor/bin/phpunit --testsuite Folio && node --test packages/folio/tests/js` — PASS.
- [ ] **Step 5: Commit** `feat(folio): Translate on a record queues a background job`

### Task 7: Run screen as status page

**Files:**
- Modify: `packages/folio/src/Http/AiTranslateController.php` (`plan`, `start`, `step`, `progress`, `unfinished`), `packages/folio/templates/admin/ai/run.twig` (and its progress partial), `packages/folio/lang/{en,de,fr}/folio.php`
- Test: `packages/folio/tests/Http/AiRunTest.php`

**Interfaces:**
- Consumes: Task 2, Task 3 (`lastRun`).
- Produces: Start queues (`start($job, confirmLive, by)`); the page keeps polling `step` while open (steps go through the lease, so a cron worker and the page never translate the same record twice); copy says the page can be left when a worker ran in the last 300 s, else `folio.ai_queue.no_worker` with the cron line. `queued` shows as "waiting", `running` as "working".
- [ ] **Step 1: Tests** in `AiRunTest`: `test_start_queues_the_job`, `test_step_skips_a_record_the_worker_holds` (claim the record on the bound lease; step leaves it pending, response 200), `test_status_page_without_worker_shows_the_cron_line`, `test_status_page_with_recent_worker_says_page_can_be_left`. Update existing assertions on `running` after start.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/folio/tests/Http/AiRunTest.php` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit --testsuite Folio` — PASS.
- [ ] **Step 5: Commit** `feat(folio): the run screen is a status page; work continues without it`

### Task 8: Dashboard widget, translations-screen hint

**Files:**
- Modify: `packages/folio/src/Http/AdminController.php` (`index`), `packages/folio/templates/admin/dashboard.twig`, admin CSS (the stylesheet `dashboard.twig` uses), `packages/folio/src/Http/TranslationsController.php`, `packages/folio/templates/admin/translations/index.twig`, `packages/folio/lang/{en,de,fr}/folio.php`
- Test: `packages/folio/tests/Integration/PublishingAppTest.php` (or the dashboard's existing test file), `packages/folio/tests/Http/AiLabelsAndMediaTest.php`

**Interfaces:**
- Consumes: Task 4 `ReviewIndex::entries()`, Task 2 `JobRunner::active()`, Task 3 `lastRun()`.
- Produces: dashboard section "Waiting for review" (`folio.dashboard.review.*`), only with AI on and `ai.translate`:
  - entries whose type the user may view (`{type}.view`), grouped by type label; each row: title, languages with counts, link `{prefix}/{type}/{id}/edit?locale={first locale}`; empty state text.
  - active jobs: languages, records done/total, cost so far, state, message for stopped, `queuedBy`, since; the no-worker line when jobs are queued and no worker ran in 300 s.
  - Settings → Translations: when the selected locale has no `sourceLocale()` and AI is on, the line `folio.translations.choose_target` instead of nothing.
- [ ] **Step 1: Tests**: `test_dashboard_lists_records_waiting_for_review` (two entries, one type the user can't view → hidden), `test_dashboard_shows_active_jobs_and_no_worker_line`, `test_dashboard_has_no_review_section_when_ai_off`, `AiLabelsAndMediaTest::test_source_language_says_choose_a_target`.
- [ ] **Step 2: Run** the named files — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit --testsuite Folio` — PASS.
- [ ] **Step 5: Commit** `feat(folio): dashboard lists translations waiting for review; hint on the source language`

### Task 9: BG Genius flow screen queues (project repo)

**Repo:** `/Users/smyr/Sites/gbits/bggenius-preflow` (its composer path repository points at the framework worktree while this runs; restore after merge).

**Files:**
- Modify: `app/Folio/Fields/FlowFieldType.php` (`aiUrl` users), `app/Folio/BgGenius/Screens/FlowEditor.php` (~56, ~503-515; drop `_ai_unchecked`/`_ai_checked` reading in `translations()`), `app/Folio/BgGenius/templates/flow-translate.twig`, `app/Folio/BgGenius/assets/flow-translate.js`, `CLAUDE.md`
- Test: `tests/js/flow-translate.test.js`, the FlowEditor PHPUnit tests that cover the AI button

**Interfaces:**
- Consumes: Task 6 queue endpoint (`aiUrl($gameId, 'queue')`, body `prefix=flow`).
- Produces: the flow screen's Translate queues the game's flow for the chosen language, shows the answer's message (and the cron hint when `worker` is false), and lists unchecked rows as today. CLAUDE.md: the cron line and `php vendor/bin/preflow translate:work` for local use.
- [ ] **Step 1: Tests**: JS `queues with prefix flow and shows the message`; PHP: the flow screen renders the queue URL, and posted `_ai_unchecked[]` no longer marks anything.
- [ ] **Step 2: Run** `vendor/bin/phpunit && node --test tests/js/*.test.js` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: flow translation runs in the background`

### Task 10: Docs, release notes

**Files:**
- Modify: `packages/folio/README.md` (AI section: queue flow, status page, widget), `ROADMAP.md` (item for 0.48), create `docs/superpowers/plans/2026-10-30-release-0.48-checklist.md` (changes, upgrade notes: cron line, removed setting, `folio:review-index` once after upgrade; test runs; release and site steps as in the 0.47 checklist)
- [ ] **Step 1:** Write the docs; run `vendor/bin/phpunit` (whole monorepo) and record the result in the checklist.
- [ ] **Step 2: Commit** `docs: background translation, 0.48 checklist`
