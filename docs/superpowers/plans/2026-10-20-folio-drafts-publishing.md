# Folio Drafts and Publishing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Editors work on a working copy that the public site never sees until it is published. The plan adds:
- autosave and version-conflict detection;
- revisions with restore;
- scheduled publishing from cron;
- signed share-preview links for people who are not logged in.

**Architecture:**
- A `Publisher` service owns the working-copy/snapshot pair.
  - Snapshots and revisions live in shadow `TypeDefinition`s built in code: `{table}__published` and `{table}__revisions`.
  - They are read and written through new definition-based `DataManager` methods, so no driver changes.
  - The state (Draft, Published, Changed) is derived by comparing content.
- Public reads (`FrontendResolver`, `folio_get`, app code via `Publisher::query()`) go to snapshots.
- The admin and the preview keep reading the working copy.
- The CLI gains discovery of app-listed commands (`config/commands.php`), so Folio can ship `folio:publish-scheduled` and `folio:publish`.

**Tech Stack:** PHP ≥ 8.4, PHPUnit 11, Twig, htmx 2.0.11 (vendored, already served by the admin), vanilla ES5 `admin.js`.

**Spec:** `docs/superpowers/specs/2026-10-19-folio-editor-experience-design.md`, section 4b.

**Branch:** `feat/drafts-publishing` from `main`.

## Global Constraints

- **Conventions:** `declare(strict_types=1)`; `final` classes and `final readonly` value
  objects; tests named `test_snake_case`.
- **CSP:** no inline handlers, `style=` attributes or un-nonced tags in admin output.
  `CspGuardTest` must stay green.
- **Opt-in:** `"publishing": true|false` in the type JSON.
  - It defaults to true for types with a `frontend` block and for singletons, and to
    false otherwise.
  - When on, `frontend.published` is ignored. When off, everything behaves exactly as today.
- **Storage:** snapshots are table `{table}__published` with the same fields and the same
  id. Revisions are table `{table}__revisions`.
  - JSON storage needs nothing extra.
  - SQL storage needs user migrations for the two tables and the system columns; the docs
    show the schema. Drivers are unchanged.
- **System keys on the working copy:** `_version` (int, 0 when absent), `_publish_at`
  (`Y-m-d\TH:i` in the app timezone, or absent), `_share_token` (32 hex).
- **System keys on the snapshot:** `_published_at` (`DATE_ATOM`) and `_published_by`
  (auth id, `'scheduler'`, `'cli'`, or null).
- **Content comparison** ignores the keys
  `_version, _publish_at, _share_token, _published_at, _published_by, _order` and the id.
- **Validation:**
  - Saving a draft of a publishing type runs no rules (`saveType(validate: false)`); field
    types still normalize shapes.
  - Publishing runs full validation: block rules plus type rules, through `RecordValidator`.
- **Revisions:** keep 20 per record (`config/folio.php` `publishing.revisions`). A
  revision is a full copy.
- **Share links:**
  - The query parameter is `folio_preview`. Links are HMAC-SHA256 with `app.key`, valid
    for 7 days (`publishing.preview_ttl` = 604800 seconds).
  - They are disabled when `app.key` is empty or equals `preflow-default-key-change-me!!`.
  - Responses send `X-Robots-Tag: noindex` and `Cache-Control: private, no-store`.
- **Autosave:** 3 s after the last `input`/`change`/`trix-change`, for existing records of
  publishing types only. It never writes uploads.

## Review Focus

1. **A publish that fails validation:**
   - the edits are kept as the working copy and the live page is untouched;
   - the errors show in the form with "Saved as draft — not published";
   - Task 5: `test_failed_publish_keeps_draft_and_live_page`.
2. **Save right after an autosave.** The autosave bumps `_version`, and its response must
   update the form's hidden `_version` (an out-of-band swap). Otherwise the editor's own
   next save is refused as a conflict (Task 6: `test_save_after_autosave_does_not_conflict`).
3. **Reordering in the list:**
   - it must not flip published records to "Changed";
   - the new order must reach the live site immediately;
   - Task 5: `test_reorder_updates_live_order_without_changing_state`.
4. **A forged, foreign or revoked share token.** It must never render a working copy: the
   request falls back to normal resolution (a draft gives 404, a changed page shows the
   published content) (Task 9: `test_tampered_foreign_or_revoked_token_is_ignored`).
5. **Turning publishing on for a type that already has records.** Every page becomes a
   draft and vanishes publicly. `php preflow folio:publish <type>` must publish them all
   in one go and report the records that fail validation (Task 8:
   `test_publish_command_publishes_every_record`).

---

### Task 1: Definition-based data access

**Files:**
- Modify: `packages/data/src/DataManager.php`
  - Add `findIn(TypeDefinition $def, string|int $id): ?DynamicRecord`.
  - Add `queryIn(TypeDefinition $def): QueryBuilder`.
  - Add `deleteIn(TypeDefinition $def, string|int $id): void`.
  - Add `validate(DynamicRecord $record, array $rules = []): void`, which throws
    `ValidationException` and is a public wrapper over `validateDynamicRecord`.
  - Rewrite `findType`/`queryType`/`deleteType` to delegate to the `*In` methods via the
    registry.
- Test: `packages/data/tests/DefinitionAccessTest.php` (JSON driver in a temp dir; build the shadow def with `new TypeDefinition(key: 'page__published', table: 'page__published', …)`).

**Interfaces:**
- Produces: the four methods above. They are used by every later task.

- [ ] **Step 1: Write the failing tests:**
  - `test_shadow_definition_round_trip`: saveType on a shadow-def record, then `findIn` returns it, `queryIn()->get()->total() === 1`, and `deleteIn` makes `findIn` return null. `findType('page', id)` stays null, so the tables are separate.
  - `test_validate_throws_without_saving`: a missing required title → `ValidationException` with key `title`, and nothing is stored.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/data/tests/DefinitionAccessTest.php`. Expected: FAIL (undefined method).
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(data): definition-based find/query/delete and validate-only`.

### Task 2: Publisher core: flag, shadow types, states, publish/unpublish/discard

**Files:**
- Modify: `packages/folio/src/Content/TypeListing.php`, adding `bool $publishing = false`.
  `TypeCatalog::scan()` sets it as
  `$data['publishing'] ?? (isset($data['frontend']) || !empty($data['singleton']))`.
- Create: `packages/folio/src/Publishing/ShadowTypes.php`
  - `static published(TypeDefinition $def): TypeDefinition`, which has the same fields and
    locales, key/table `{x}__published`.
  - `static revisions(TypeDefinition $def): TypeDefinition`, with fields
    `record_id, seq, data, changed, created_at, created_by` and idField `uuid`.
- Create: `packages/folio/src/Publishing/PublishState.php`, a string-backed enum with
  `Draft = 'draft'`, `Published = 'published'` and `Changed = 'changed'`.
  `label(): string` returns `Draft`, `Published` or `Changed`.
- Create: `packages/folio/src/Publishing/VersionConflict.php`
  (`final class extends \RuntimeException`, with `public readonly int $current`).
- Create: `packages/folio/src/Content/RecordValidator.php`
  - `__construct(FieldTypeRegistry, DataManager)`.
  - `rules(TypeDefinition): array`, moved from `AdminController::rulesFor`.
  - `validate(DynamicRecord): void`: block errors keyed `<field>.<uid>.<leaf>`, then
    `dm->validate($record, rules)`. Both raise one `ValidationException`, the
    `saveWithBlocks` logic.
- Create: `packages/folio/src/Publishing/Publisher.php`
  - `__construct(DataManager $dm, TypeCatalog $catalog, RecordValidator $validator, int $keepRevisions = 20, ?\Closure $now = null)`, where `$now` is `fn(): \DateTimeImmutable`.
  - `const META = ['_version','_publish_at','_share_token','_published_at','_published_by','_order']`.
  - `enabled(string $type): bool`
  - `snapshot(string $type, string $id): ?DynamicRecord`
  - `live(string $type, string $id): ?DynamicRecord`: the snapshot when enabled, else the
    working copy.
  - `query(string $type): QueryBuilder`: snapshots when enabled, else `queryType`.
  - `state(DynamicRecord $working): PublishState`
  - `states(string $type): array<string, PublishState>`: one snapshot query for a whole
    list.
  - `saveDraft(DynamicRecord $working, ?int $loadedVersion): void`
    - Throws `VersionConflict` when `$loadedVersion !== null` and differs from the stored
      `_version`.
    - Sets `_version` = stored + 1 and saves with `validate: false`.
  - `publish(DynamicRecord $working, ?string $by): DynamicRecord`
    - Runs `validator->validate()` first.
    - Writes the snapshot (working content plus stamps) and clears `_publish_at` on the
      working copy (no version bump).
    - Appends a revision (Task 3), then returns the snapshot.
  - `unpublish(string $type, string $id): void`
  - `discard(string $type, string $id): ?DynamicRecord`: the working copy becomes the
    snapshot content, keeping its `_share_token`, with `_version` + 1. Returns null when
    there is no snapshot.
- Modify: `AdminController`, which uses `RecordValidator` instead of `saveWithBlocks`/`rulesFor`. Behaviour is unchanged.
- Modify: `FolioServiceProvider`, which binds `RecordValidator` and `Publisher` (singletons; `keepRevisions` from `publishing.revisions`).
- Test: `packages/folio/tests/Publishing/PublisherTest.php`, using a temp models dir with `post.json` (a frontend type) and `memo.json` (no frontend).

**Interfaces:**
- Consumes: Task 1.
- Produces: everything listed above. `Publisher::publish` throws `ValidationException`
  untouched.

- [ ] **Step 1: Write the failing tests:**
  - `test_publishing_defaults`: `post` (frontend) → enabled; `memo` → not; a `memo` with `"publishing": true` → enabled; a singleton → enabled; a frontend type with `"publishing": false` → not.
  - `test_states_follow_content`: no snapshot → Draft; after publish → Published; after changing the title and calling saveDraft → Changed; after `discard` → Published, with the title back.
  - `test_meta_keys_do_not_change_state`: after publish, set `_publish_at`, `_order` and `_share_token`, then saveDraft → still Published.
  - `test_publish_stamps_snapshot`: `_published_at` equals the injected clock's `DATE_ATOM`; `_published_by === 'u@x'`; the working copy's `_publish_at` is cleared.
  - `test_publish_validates_fully_but_draft_does_not`: `saveDraft` with an empty required title succeeds; `publish` throws `ValidationException` with key `title`; no snapshot exists.
  - `test_unpublish_removes_snapshot`.
  - `test_version_conflict`: stored `_version` 3 and `saveDraft(…, 2)` → `VersionConflict` with `current === 3`. `saveDraft(…, 3)` → stored 4. `saveDraft(…, null)` → no check.
  - `test_live_and_query_read_snapshots_only_when_enabled`.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/folio/tests/Publishing/PublisherTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** The content comparison is `json_encode` of the recursively
  key-sorted `toArray()` minus `META` and the id.
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS (AdminController behaviour unchanged).
- [ ] **Step 5: Commit** `feat(folio): publisher with working copy, snapshot and derived states`.

### Task 3: Revisions

**Files:**
- Modify: `Publisher`, adding:
  - `revisions(string $type, string $id): list<Revision>`, newest first by `seq`;
  - `restore(string $type, string $id, string $revisionId): DynamicRecord`: the working
    copy gets the revision content, keeps its `_share_token`, `_version` + 1; returns it;
  - an append on every `publish`: `seq` = max + 1; `changed` = the top-level field names
    whose content differs from the previous snapshot (all non-empty fields for the first);
    prune to `keepRevisions`.
- Create: `packages/folio/src/Publishing/Revision.php`
  - `final readonly`: `string $id, int $seq, array $data, list<string> $changed, string $createdAt, ?string $createdBy`.
- Test: add to `PublisherTest.php`.

- [ ] **Step 1: Write the failing tests:**
  - `test_publish_appends_revision_with_changed_fields`: publish (title A), change body, publish again → 2 revisions; the newest has `changed === ['body']`.
  - `test_revisions_are_capped`: `keepRevisions: 3` and 5 publishes → 3 revisions, seq 5, 4, 3.
  - `test_restore_copies_into_working_copy_only`: restore the first revision → the working title is A, the state is Changed, the snapshot is unchanged.
- [ ] **Step 2: Run** the filter `--filter 'revision|restore'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): revisions on publish, capped, restorable`.

### Task 4: Public reads come from snapshots

**Files:**
- Modify: `packages/folio/src/Content/FrontendRoute.php`. `fromConfig(string $type, array $frontend, bool $publishing = false)` and a public `bool $publishing`.
- Modify: `FrontendResolver`
  - `fromCatalog`/`routesFromCatalog` pass `TypeListing::$publishing`.
  - `match()` queries `queryIn(ShadowTypes::published($def))` and skips the
    published-field `where` when `$route->publishing` is set.
- Modify: `packages/folio/src/Content/Singletons.php`, which reads through `Publisher::live($type, $type)`.
- Modify: `packages/folio/tests/Integration/FolioAppTest.php` scaffold helpers
  (`scaffold`, `writeArticleType`, `writeSiteType`). Existing types get
  `"publishing": false`, so the legacy convention stays pinned.
- Test: `packages/folio/tests/Integration/PublishingAppTest.php`. It is a new file with its own scaffold: a `post` frontend type `/{slug}` (title required) and a `site` singleton, multilingual en/de.

- [ ] **Step 1: Write the failing tests:**
  - `test_public_site_reads_snapshot_preview_reads_working_copy`: publish title "Live", saveDraft title "Draft" → GET `/hello` contains "Live" and not "Draft"; POST the preview contains "Draft".
  - `test_unpublished_record_is_404`: a saved draft only → 404; after unpublish → 404.
  - `test_locale_availability_comes_from_snapshot`: publish with `_locales [en]`, then saveDraft with `[en, de]` → `/de/hello` is 404 until published again.
  - `test_folio_get_reads_singleton_snapshot`.
  - `test_published_field_is_ignored_when_publishing`: a published snapshot whose `status` is "draft" still renders.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/folio/tests/Integration/PublishingAppTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS (legacy tests still green with `"publishing": false`).
- [ ] **Step 5: Commit** `feat(folio): frontend and folio_get read published snapshots`.

### Task 5: Editor: save vs publish, states, conflict, unpublish/discard

**Files:**
- Modify: `AdminController`
  - For publishing types, `store`/`update`:
    1. `drafts->build` the record.
    2. Apply `_publish_at` from POST (valid `Y-m-d\TH:i` or cleared).
    3. Call `publisher->saveDraft($record, $loaded)`, where `$loaded` is the int from
       POST `_version`, or null when absent.
    4. If POST `_action === 'publish'`, call `publisher->publish($record, $userId)`.
  - The user id is `$request->getAttribute(Authenticatable::class)?->getAuthId()`.
  - On `ValidationException` from publish: 422 form with errors and the notice
    "Saved as draft — not published."
  - On `VersionConflict`: 409 form with the submitted values, hidden `_version` =
    `current`, and the notice "Someone else changed this record. Save again to
    overwrite, or reload to discard your edits." (with a reload link to the edit URL).
  - Non-publishing types keep today's flow.
  - `list` adds a state per row from `publisher->states($type)`.
  - `reorder` writes `_order` into the snapshot too, when one exists.
  - `destroy` also deletes the snapshot and all revisions.
- Create: `packages/folio/src/Http/PublishingController.php`
  - `unpublish` (POST `{p}/{type}/{id}/unpublish`) and `discard` (POST `{p}/{type}/{id}/discard`).
  - Both redirect 302 to the edit URL. A non-publishing type → 404.
- Modify: `FolioRoutes::admin`: the two rows above, before `{p}/{type}/{id}`.
- Modify: `form.twig`. When `publishing` is in context:
  - a state badge (`folio-badge is-{state}`) in `page_title`;
  - a hidden `_version`;
  - a "Publish at" `datetime-local` input named `_publish_at`;
  - buttons: "Save draft" (submit), "Publish" (`name="_action" value="publish"`),
    "Discard changes" when Changed and "Unpublish" when not Draft. These two are separate
    small forms posting with `_csrf_token`, placed outside the main form.
- Modify: `list.twig`, with a state badge column when the type publishes.
- Modify: `admin.css`, with `is-draft`, `is-published` and `is-changed` badge colours.
- Test: add to `PublishingAppTest.php`.

- [ ] **Step 1: Write the failing tests:**
  - `test_save_draft_with_failing_rules_succeeds`: POST with an empty title → 302, stored, live 404.
  - `test_publish_action_publishes`: POST `_action=publish` → live shows the title, and the list contains `is-published`.
  - `test_failed_publish_keeps_draft_and_live_page`: published "Live", then POST title "" with `_action=publish` → 422 with "Saved as draft — not published"; the working title is ""; live still "Live".
  - `test_stale_version_is_refused`: POST `_version=0` while stored is 2 → 409 with "Someone else changed this record"; the response holds `name="_version" value="2"` and the stored record is unchanged. POSTing again with `_version=2` → 302.
  - `test_unpublish_and_discard_routes`: unpublish → live 404 and state Draft; discard after a change → the title is restored.
  - `test_reorder_updates_live_order_without_changing_state`: two published orderable posts, move one → both stay Published and the snapshot `_order` values are swapped.
  - `test_destroy_removes_snapshot_and_revisions`.
  - `test_publish_at_is_stored_and_cleared_on_publish`.
- [ ] **Step 2: Run** the file. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS, with `CspGuardTest` green.
- [ ] **Step 5: Commit** `feat(folio): save draft, publish, unpublish, discard and conflicts in the admin`.

### Task 6: Autosave

**Files:**
- Modify: `PublishingController`, adding `autosave` (POST `{p}/{type}/{id}/autosave`)
  1. Build the record with `writeUploads: false` and call `saveDraft`.
  2. Render `@folio/admin/publishing/status.twig`: a `<span id="folio-save-state">` with
     "Saved · not published" (Draft or Changed) or "Published", plus
     `<input type="hidden" name="_version" id="folio-version" value="N" hx-swap-oob="true">`.
  3. On a conflict, return 200 with the state text "Not saved — someone else changed this
     record" and no OOB version.
  4. Return 404 for a non-publishing type or an unknown id.
- Modify: `form.twig` for existing records of publishing types:
  - the form gets `hx-post="{{ autosave_url }}"`,
    `hx-trigger="input changed delay:3s, change delay:3s, trix-change delay:3s"`,
    `hx-target="#folio-save-state"` and `hx-swap="outerHTML"`;
  - the hidden `_version` gets `id="folio-version"`;
  - the header shows the `#folio-save-state` span.
  - `AdminController::form` adds `htmx.js` to `editor_assets` for publishing types.
- Modify: `admin.js`. Block move, remove and duplicate dispatch a bubbling `change` on the
  form.
- Test: `PublishingAppTest.php`, plus an `AdminJsTest` assertion.

- [ ] **Step 1: Write the failing tests:**
  - `test_autosave_saves_working_copy_only`: POST autosave with title "Auto" → 200, contains `Saved · not published`, live unchanged, stored title "Auto".
  - `test_save_after_autosave_does_not_conflict`: read `_version` from the autosave response's OOB input, then POST update with it → 302.
  - `test_autosave_conflict_reports_and_does_not_save`.
  - `test_autosave_writes_no_files`: a file in the autosave POST → no new file under uploads.
  - `test_form_wires_autosave_for_publishing_types_only`: an edit form of `post` contains `hx-trigger="input changed delay:3s`; the new-record form and non-publishing forms do not.
  - `test_block_actions_dispatch_change` (AdminJsTest).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): debounced autosave of working copies`.

### Task 7: Revisions UI

**Files:**
- Modify: `AdminController::form`. The edit form of a publishing type gets `revisions`
  (from `publisher->revisions`).
- Modify: `form.twig`, adding a `<details class="folio-revisions">` panel with "Revisions".
  - Each row shows the date (`created_at`), the user (or "—") and the changed field
    labels.
  - Each row has a "Restore" form (POST, `_csrf_token`).
  - The panel shows "Restored into the working copy — publish to make it live." when
    `?restored=1`.
- Modify: `PublishingController`, adding `restore` (POST `{p}/{type}/{id}/revisions/{rev}/restore`)
  → 302 to `…/edit?restored=1`. An unknown revision → 404.
- Modify: `FolioRoutes::admin`, adding the restore row.
- Test: add to `PublishingAppTest.php`.

- [ ] **Step 1: Write the failing tests:**
  - `test_edit_form_lists_revisions`: two publishes → two `folio-revision` rows, the newest first, naming the changed field label.
  - `test_restore_route_restores_and_redirects`.
  - `test_restore_unknown_revision_is_404`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): revision list and restore in the editor`.

### Task 8: CLI: app commands, scheduled publish, publish-all

**Files:**
- Modify: `packages/devtools/src/Console.php`
  - `__construct(?string $basePath = null)` registers every class listed in
    `{basePath ?? getcwd()}/config/commands.php`, which returns `list<class-string<CommandInterface>>`.
  - Each class is instantiated with no args.
  - A class that is missing or not a `CommandInterface` → a warning to STDERR, then
    continue.
- Modify: `packages/folio/composer.json`, adding the requirement `preflow/devtools`.
- Create: `packages/folio/src/Console/FolioCommands.php`, with `static all(): list<class-string>`.
- Create: `packages/folio/src/Console/BootsApplication.php`
  - A trait taking `?\Closure $appFactory`: `app(): Application`, defaulting to
    `Application::create(getcwd())->boot()`, as in `NotifyTestCommand`.
- Create: `packages/folio/src/Publishing/Scheduler.php`
  - `__construct(Publisher, TypeCatalog, DataManager)`.
  - `runDue(\DateTimeImmutable $now): array{published: list<string>, failed: array<string, string>}`,
    keyed `type/id`.
  - It covers publishing types whose working copy `_publish_at` ≤ `$now`, filtered in PHP.
  - A failure keeps `_publish_at` and records the first validation message.
- Create: `packages/folio/src/Console/PublishScheduledCommand.php`
  - Name `folio:publish-scheduled`, by `'scheduler'`.
  - It runs `flock(LOCK_EX|LOCK_NB)` on `storage/locks/folio-publish-scheduled.lock`.
    When the lock is held it prints "Already running." and exits 0.
  - It prints one line per published or failed record, and exits 1 if any failed.
- Create: `packages/folio/src/Console/PublishCommand.php`
  - `folio:publish <type> [--id=<id>]`, by `'cli'`.
  - It publishes every working copy (or one), with the same output and exit rules.
- Modify: `skeleton/config/commands.php` (create), listing `...\Preflow\Folio\Console\FolioCommands::all()`. Also `examples/folio-demo/config/commands.php`.
- Test: `packages/devtools/tests/ConsoleAppCommandsTest.php`,
  `packages/folio/tests/Publishing/SchedulerTest.php` and
  `packages/folio/tests/Console/PublishCommandsTest.php` (temp app via the
  PublishingAppTest scaffold, extracted into a `tests/Support/PublishingScaffold.php`
  trait).

- [ ] **Step 1: Write the failing tests:**
  - `test_console_registers_app_listed_commands`: a temp `config/commands.php` listing a fixture command → `run(['preflow','fixture:hello'])` returns 0; a bogus class gives a warning, not a crash.
  - `test_runs_due_items_once`: two posts, one due (`_publish_at` in the past) and one in the future → `published === ['post/<id1>']`; a second run publishes nothing.
  - `test_failing_due_item_stays_scheduled`.
  - `test_concurrent_run_is_locked_out`: hold the lock file with `flock` in the test → the command prints "Already running." and exits 0, and the due item is not published.
  - `test_publish_command_publishes_every_record`: three drafts, one invalid → two published; the output names the invalid one; exit 1.
- [ ] **Step 2: Run** the three files. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): scheduled publishing and publish-all commands`.

### Task 9: Share preview links

**Files:**
- Create: `packages/folio/src/Publishing/PreviewLinks.php`
  - `__construct(string $key, int $ttl = 604800, ?\Closure $now = null)`.
  - `enabled(): bool`: false when the key is empty or the default placeholder.
  - `create(string $type, string $id, string $nonce): string`: the token is
    `b64url(json [type,id,nonce,expires]) . '.' . b64url(hmac)`.
  - `verify(string $token): ?PreviewTarget`: null for a bad shape, a bad signature (via
    `hash_equals`) or an expired token.
- Create: `packages/folio/src/Publishing/PreviewTarget.php`
  (`final readonly`: `type`, `id`, `nonce`, `expiresAt`).
- Modify: `PublishingController`, adding:
  - `share` (POST `{p}/{type}/{id}/share`): creates `_share_token` if missing (saved
    without a version bump), then renders `@folio/admin/publishing/share.twig`. That is a
    readonly input with `urlFor(record, locale) . '?folio_preview=' . token`, a "Copy"
    button (`data-folio-copy`) and a "Revoke all links" form.
  - `revoke` (POST `…/share/revoke`): a new `_share_token`, then the fragment "Links
    revoked."
  - Either returns 404 when links are disabled or the type has no route.
- Modify: `FrontendController::show`. With a `folio_preview` query param that verifies, and
  whose nonce equals the working copy's `_share_token`, and where
  `resolver->urlFor(workingCopy, locale)` equals the request path (so a link follows the
  record's current working-copy slug):
  1. Render the working copy with the route's template, `preview => true`, and the request
     locale.
  2. Add the noindex and no-store headers.
  3. Otherwise, resolve normally.
- Modify: `form.twig`, with a "Copy preview link" button (`hx-post` to the share URL,
  target a `#folio-share` slot) when links are enabled and the type has a route.
  `admin.js` adds a `[data-folio-copy]` click handler (`navigator.clipboard`, falling back
  to selecting the input).
- Modify: `FolioServiceProvider`, which binds `PreviewLinks` from `app.key` and
  `publishing.preview_ttl`.
- Test: `packages/folio/tests/Publishing/PreviewLinksTest.php`, plus additions to `PublishingAppTest.php`.

- [ ] **Step 1: Write the failing tests:**
  - `test_valid_link_renders_working_copy_with_headers`: a draft "Secret", share → GET the link without login → 200 containing "Secret", `X-Robots-Tag: noindex`, `Cache-Control: private, no-store`.
  - `test_expired_link_falls_back`: the clock is 8 days later → the draft gives 404.
  - `test_tampered_foreign_or_revoked_token_is_ignored`: a flipped signature byte → 404. A valid token for post A used on post B's URL → normal resolution of B. A token whose type/id does not exist → normal resolution. After revoke, the old link → 404 for a draft and published content for a changed page.
  - `test_links_disabled_with_default_key`: `enabled() === false`; share route 404; no button in the form.
  - `test_verify_rejects_garbage`: `''`, `'a.b'`, non-base64 → null.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS, with `CspGuardTest` green.
- [ ] **Step 5: Commit** `feat(folio): signed share-preview links`.

### Task 10: Demo, docs, browser check

**Files:**
- Modify: `examples/folio-demo/config/models/page.json`, `site.json` and `article.json`:
  drop `status` and `frontend.published` (publishing is on by default).
- Modify: `examples/folio-demo/seed.php`, which publishes each seeded record via `Publisher::publish($record, 'seed')`.
- Modify: `packages/folio/README.md` (or the docs page that covers Folio), with a
  "Drafts and publishing" section:
  - the opt-in key;
  - the states;
  - autosave;
  - revisions config;
  - the cron line `*/5 * * * * cd /path && php preflow folio:publish-scheduled`;
  - `folio:publish`;
  - share links and the `APP_KEY` requirement;
  - reading live content in app code via `Publisher::query()`;
  - the SQL schema for `{table}__published`, `{table}__revisions` and the system columns.
- Test: the existing demo test (`seed` into a temp copy, GET `/` → 200).

- [ ] **Step 1: Update the demo test** to assert that the home page renders after seeding
  and that the admin page list shows `is-published`. Run it; Expected: FAIL.
- [ ] **Step 2: Update the models and the seed.** Run `vendor/bin/phpunit`; Expected: PASS.
- [ ] **Step 3: Write the docs section.**
- [ ] **Step 4: Browser check** on the demo (`APP_DEBUG=0 php -S 127.0.0.1:8769 examples/folio-demo/public/index.php`). Check:
  - an edit autosaves after 3 s, then a manual Save succeeds;
  - Publish updates the live page, and Discard and Unpublish work;
  - restoring a revision works;
  - a share link opens in a private window;
  - the CSP shows no violations.
- [ ] **Step 5: Commit** `docs(folio): drafts and publishing; demo publishes its seed`, and
  update `docs/superpowers/plans/README.md` with row 15.
