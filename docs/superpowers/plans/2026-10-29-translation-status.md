# Translation Status (missing, outdated, complete-language publishing) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Folio knows, for every translatable text of a record (plain fields, block leaves, and texts a custom field
type reports), whether a language is missing it or holds an outdated translation; it shows both in the list and the
form, offers "still correct", and can refuse to publish while a visible language is incomplete.

**Architecture:** One walker (`TranslationStatus`) turns a record into a flat list of `TranslatableText`s (path,
label, locale map) from plain translatable fields, `BlocksFieldType` leaves and any field type implementing the new
`ReportsTranslatableTexts` interface. Missing = non-empty in the default locale, empty in the locale. Outdated = the
record-level `_translated_from` map holds a fingerprint of the default text the translation was made from, and it no
longer matches. `DraftRecordFactory::build` (shared by save, autosave, preview) stamps fingerprints when a
translation changes. `Publisher::publish()` refuses (as a `ValidationException`) when the opt-in
`publishing.complete_languages` is on and a locale in `_locales` misses a text.

**Tech Stack:** PHP 8.4, Preflow monorepo (`packages/folio`, `packages/data`), PHPUnit, Twig.

**Spec:** `/Users/smyr/Sites/gbits/bggenius-preflow/docs/superpowers/specs/2026-10-29-phase3-into-folio-design.md`
(section 3 "Flows and translation", Decisions "Publishing waits for complete languages", Constraints). This plan is
the "one Preflow release with the generic Folio parts" the spec's Size section names; it ships as v0.45.0.

## Global Constraints

- Generic Folio features only; no BG Genius concept in the framework.
- Opt-in or harmless for existing sites: the publish refusal is off unless `config/folio.php`
  `'publishing' => ['complete_languages' => true]`. Outdated marks appear only for translations saved after the
  upgrade (no fingerprint = not outdated).
- Outdated never blocks publishing (owner).
- A refused publish keeps the draft saved (today's behaviour: "Saved as draft — not published.").
- `lang/en`, `lang/de`, `lang/fr` keep identical keys (`tests/TranslationsDeclarationTest.php`).
- New column `_translated_from` is free TEXT on every Folio record table, its `__published` and `__revisions` tables
  (via `TableSync::SYSTEM`), added by `preflow migrate` (`folio.tables`), never altering existing columns.
- After the release: smeyer.me, smart-stack.works and holz-objekte.org are upgraded and deployed (standing rule).

## Review Focus

1. **A translation saved in the same request as a default-language change** (an editor on the German tab cannot
   change English, but import, seed or API code can write both): the fingerprint must be taken from the *new*
   default text, so the translation is not instantly outdated. Test in Task 1.
2. **Autosave on the German tab** stamps fingerprints exactly like a full save (autosave skips hooks). Test in
   Task 1.
3. **A block deleted, reordered or added**: paths are uid-based, so reordering keeps statuses; a deleted block's
   fingerprints are dropped on the next save; a new block's leaves count as missing in German. Test in Task 1.
4. **Restoring a revision** brings its `_translated_from` with it (the column is content, not META), so restored
   German text is judged against the fingerprints it was saved with. Test in Task 2.
5. **Scheduled publishing of an incomplete record** fails with a readable reason and keeps the schedule. Test in
   Task 2.

---

### Task 1: `TranslationStatus`, the field-type interface, and fingerprints on save

**Files:**
- Create: `packages/folio/src/Content/TranslatableText.php`, `packages/folio/src/Content/TranslationStatus.php`,
  `packages/folio/src/Field/ReportsTranslatableTexts.php`
- Modify: `packages/folio/src/Field/Types/BlocksFieldType.php` (implement the interface),
  `packages/folio/src/Content/DraftRecordFactory.php` (stamp fingerprints),
  `packages/folio/src/Storage/TableSync.php:34` (add `_translated_from` to `SYSTEM`),
  `packages/folio/src/FolioServiceProvider.php` (bind `TranslationStatus`)
- Test: `packages/folio/tests/Content/TranslationStatusTest.php`,
  `packages/folio/tests/Content/DraftRecordFactoryTest.php` (new cases), `packages/folio/tests/Storage/TableSyncTest.php`
  (column list)

**Interfaces:**
- Produces:
  - `final readonly class TranslatableText { __construct(public string $path, public string $label, public array $values) }`
    — `$values` is `array<string locale, ?string>`; `$path` is stable: a plain field is `{field}`, a block leaf is
    `{field}.{uid}.{leaf}` (nested: `{field}.{uid}.{child}.{cuid}.{leaf}`), a custom type's paths are
    `{field}.{its own path}`. `$label` is human-readable (`Title`, `Sections › Hero › Headline`).
  - `interface ReportsTranslatableTexts { public function translatableTexts(mixed $value, array $config, Locales $locales): array; }`
    returning `list<TranslatableText>` with paths relative to the field (the walker prefixes the field name and label).
  - `final class TranslationStatus` with
    - `texts(TypeDefinition $def, DynamicRecord $record): list<TranslatableText>`
    - `missing(TypeDefinition $def, DynamicRecord $record, string $locale): list<TranslatableText>`
    - `outdated(TypeDefinition $def, DynamicRecord $record, string $locale): list<TranslatableText>`
    - `fingerprint(?string $defaultText): string` — `substr(hash('sha256', trim($defaultText ?? '')), 0, 16)`
    - `confirm(TypeDefinition $def, DynamicRecord $record, string $locale, ?string $pathPrefix = null): DynamicRecord`
      — sets the fingerprints of all (or the prefixed) texts that have a value in `$locale` to the current default
      text; returns the updated record (not saved).
  - Record metadata `_translated_from`: `array<string locale, array<string path, string fingerprint>>`, stored as JSON
    text; absent or no entry for a path = not outdated.

- [ ] **Step 1: Write the failing tests** in `TranslationStatusTest` against the `PublishingScaffold`'s `post` type
  (en/de, translatable `title`, blocks fixtures):
  - `test_plain_field_missing_when_default_has_text_and_locale_empty` — `title {en:"Hello"}` → `missing(…,'de')`
    paths `['title']`; with `{en:"Hello",de:"Hallo"}` → `[]`; with `{en:""}` → `[]` (nothing to translate).
  - `test_block_leaves_are_walked_with_uid_paths` — a Hero block with translatable `headline {en:"A"}` → missing path
    `sections.<uid>.headline`, label contains `›`.
  - `test_custom_field_type_reports_its_texts` — a test field type implementing `ReportsTranslatableTexts` returning
    two texts → both appear prefixed `{field}.`.
  - `test_outdated_when_default_changed_after_translation` — `_translated_from {de:{title: fingerprint("Hello")}}`,
    title `{en:"Hello!",de:"Hallo"}` → outdated `['title']`; with `en:"Hello"` → `[]`; without an entry → `[]`.
  - `test_confirm_sets_current_fingerprints` — after `confirm(…,'de')` `outdated` is `[]`; with prefix `sections`
    only block texts change.
  - In `DraftRecordFactoryTest`: `test_saving_a_translation_stamps_its_fingerprint` (build with locale `de`, title
    `Hallo` → `_translated_from.de.title === fingerprint(en title)`), `test_unchanged_translation_keeps_old_fingerprint`
    (default changed on the `en` tab → German entry unchanged, so it becomes outdated),
    `test_default_and_translation_written_together_take_new_default` (Review Focus 1),
    `test_deleted_block_drops_its_fingerprints` (Review Focus 3).
  - `TableSyncTest`: the expected SYSTEM column list includes `_translated_from`.

- [ ] **Step 2: Run them to verify they fail**
  Run: `vendor/bin/phpunit packages/folio/tests/Content/TranslationStatusTest.php packages/folio/tests/Content/DraftRecordFactoryTest.php packages/folio/tests/Storage/TableSyncTest.php`
  Expected: FAIL (classes missing, column missing).

- [ ] **Step 3: Implement.** `BlocksFieldType` implements `ReportsTranslatableTexts` by walking leaves the way
  `validateBlocks()` does (registry definitions, nested `BlocksFieldType` children), skipping unknown blocks.
  `TranslationStatus::texts()` covers top-level translatable fields (`TypeDefinition::translatableFields()`; values
  read with `LocaleValue::map`) plus every field whose type implements the interface; it returns `[]` for a
  single-locale app. Plain values are compared as strings after `trim()`; empty string and null are empty.
  In `DraftRecordFactory::build`, after values are merged: for the locale being edited, if it is not the default,
  compare each text's value in that locale with the existing record's; where it changed (or was set) store the
  fingerprint of the *built* record's default text; then drop entries whose paths no longer exist.

- [ ] **Step 4: Run the tests to verify they pass**, then the whole Folio suite:
  `vendor/bin/phpunit --testsuite Folio` — Expected: OK (update the pinned SYSTEM list only).

- [ ] **Step 5: Commit**
  ```bash
  git add packages/folio && git commit -m "feat(folio): translation status, missing and outdated texts with fingerprints"
  ```

### Task 2: Refuse publishing while a visible language is incomplete

**Files:**
- Modify: `packages/folio/src/Publishing/Publisher.php` (constructor flag, check in `publish()`),
  `packages/folio/src/FolioServiceProvider.php:433-437` (read `publishing.complete_languages`),
  `packages/folio/src/Http/AdminController.php` (`locale_errors` also from `_translations.<locale>` keys),
  `packages/folio/lang/{en,de,fr}/folio.php`
- Test: `packages/folio/tests/Publishing/PublisherTest.php`, `packages/folio/tests/Publishing/SchedulerTest.php`,
  `packages/folio/tests/Integration/PublishingAppTest.php`

**Interfaces:**
- Consumes: `TranslationStatus::missing()` (Task 1).
- Produces: `Publisher::__construct(…, int $keepRevisions = 20, ?\Closure $now = null, ?TranslationStatus $translations = null, bool $completeLanguages = false)`
  (append at the end; keep existing positional args). A refusal is
  `ValidationException` with errors `['_translations.{locale}' => [FolioText 'folio.publish.incomplete' with :language and :texts]]`,
  `:texts` = up to 5 labels joined by `, ` plus `… (+N)`.

- [ ] **Step 1: Write the failing tests**
  - `PublisherTest::test_publish_refused_while_visible_locale_misses_texts` — flag on, `_locales [en,de]`, title
    `{en:"Hi"}` → `ValidationException` with key `_translations.de`; nothing published; the working copy (draft) is
    still saved by the caller path (assert snapshot absent).
  - `test_publish_allowed_when_locale_not_visible` — `_locales [en]` → published.
  - `test_outdated_does_not_block_publishing` — complete but outdated → published.
  - `test_flag_off_keeps_todays_behaviour` — flag off, missing German → published.
  - `test_restore_brings_back_fingerprints` (Review Focus 4) — publish with fingerprints A, change, publish B,
    restore revision 1 → working `_translated_from` equals A.
  - `SchedulerTest::test_incomplete_record_fails_and_keeps_schedule` (Review Focus 5) — `failed` holds
    `type/id => "_translations.de: …"`; `_publish_at` kept.
  - `PublishingAppTest::test_form_shows_incomplete_language_and_links_to_it` — POST `_action=publish` → 422,
    body contains "Saved as draft — not published." and a link `?locale=de` with the German language name.

- [ ] **Step 2: Run them to verify they fail**
  Run: `vendor/bin/phpunit packages/folio/tests/Publishing`
  Expected: FAIL.

- [ ] **Step 3: Implement.** The check runs in `publish()` right after `$this->validator->validate($working)`, for each
  locale of `$working->availableLocales()` except the default. The form's `locale_errors` builder accepts keys
  `_translations.{locale}` (label: the language name; field: empty). Add `folio.publish.incomplete`
  (en: `':language is missing :texts'`) in all three languages.

- [ ] **Step 4: Run the tests**, then `vendor/bin/phpunit --testsuite Folio` — Expected: OK.

- [ ] **Step 5: Commit**
  ```bash
  git add packages/folio && git commit -m "feat(folio): optionally refuse publishing while a visible language is incomplete"
  ```

### Task 3: Show missing and outdated in the admin; "still correct"; docs and release checklist

**Files:**
- Modify: `packages/folio/src/Http/AdminController.php` (`languageBadges()` at :1142 uses `TranslationStatus`;
  `form()` adds per-field `outdated` and per-tab counts), `packages/folio/templates/admin/form.twig`,
  `packages/folio/templates/admin/_list_results.twig:72`, `packages/folio/assets/admin.css`,
  `packages/folio/src/Routing/FolioRoutes.php` (confirm route), `packages/folio/lang/{en,de,fr}/folio.php`,
  `packages/folio/README.md` (Translations section), `ROADMAP.md`
- Create: `docs/superpowers/plans/2026-10-29-release-0.45-checklist.md`
- Test: `packages/folio/tests/Integration/FolioAppTest.php`, `packages/folio/tests/Integration/PublishingAppTest.php`

**Interfaces:**
- Consumes: `TranslationStatus` (Task 1).
- Produces: route `POST {prefix}/{type}/{id}/_translations/confirm` with body `locale`, optional `path` (prefix),
  `_version`, CSRF; needs the type's edit permission; saves the draft via `Publisher::saveDraft` (or a plain save for
  non-publishing types) with the version check; redirects to the form on that locale with the notice
  `folio.form.confirmed`.
  Badges become structured: `languageBadges()` returns `list<array{text: string, state: 'missing'|'outdated'|'ok'}>`;
  the template uses `state` instead of matching the word "missing".

- [ ] **Step 1: Write the failing tests**
  - Update `FolioAppTest::test_list_shows_missing_translation_badge`: missing now counts every text that has a
    default value (not only required fields); assert the new count and class `is-incomplete`.
  - `test_list_shows_outdated_badge` — `de · 1 outdated`, class `is-outdated`.
  - `test_form_marks_outdated_field_and_offers_still_correct` — German tab shows the mark next to `title` and a
    button posting to `_translations/confirm` with `path=title`; a structured field shows "N texts outdated" with a
    button for its prefix.
  - `test_language_tab_shows_counts` — the German tab label carries the missing/outdated count.
  - `PublishingAppTest::test_still_correct_clears_outdated_and_keeps_draft` — POST confirm → redirect with notice;
    no longer outdated; the record is a draft with changes (not published); a stale `_version` → 409.
  - `test_confirm_needs_edit_permission` — 403 without it.

- [ ] **Step 2: Run them to verify they fail**
  Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php packages/folio/tests/Integration/PublishingAppTest.php`
  Expected: FAIL.

- [ ] **Step 3: Implement** the badge, marks, counts, route and strings (`folio.list.outdated`
  `':locale · :count outdated'`, `folio.form.outdated`, `folio.form.outdated_texts` `':count texts outdated'`,
  `folio.form.still_correct`, `folio.form.confirmed`; en, de, fr). README "Translations": missing/outdated rules,
  fingerprints, `ReportsTranslatableTexts` with a short example, `publishing.complete_languages`, still correct.
  Checklist in the 0.44 checklist's shape (changes, breaking changes: the badge counts all default-filled texts and
  `languageBadges` is structured; `_translated_from` column via `preflow migrate`; test runs; release; the three
  sites). ROADMAP: a short entry under the BG Genius section ("Phase 3 framework part: translation status, 0.45").

- [ ] **Step 4: Run the full suite** `vendor/bin/phpunit` — Expected: OK (timing tests may need a rerun alone).

- [ ] **Step 5: Commit**
  ```bash
  git add packages/folio ROADMAP.md docs/superpowers/plans/2026-10-29-release-0.45-checklist.md
  git commit -m "feat(folio): missing and outdated translations in list and form, still correct; docs"
  ```
