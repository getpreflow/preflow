# Redirects and the "Not Found" List Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Old addresses of renamed, removed and hand-listed pages answer with a redirect instead of a 404, and the 404s visitors actually hit are listed in Folio so they can be turned into redirects.

**Architecture:** `preflow/routing` gets the mechanism: a `RedirectMiddleware` that catches a thrown `NotFoundHttpException` on public GET/HEAD, looks the address up in a bound `RedirectStore` (exact, then longest area), resolves record targets through `RedirectTargets`, and otherwise records the 404 in a `NotFoundLog` after a noise filter. `preflow/core` gets the `NonPublicPaths` registry that packages fill. Folio supplies the three stores (its own internal tables, SQL or JSON), an address book (`RecordAddresses`) that compares a record's live addresses after every publish, save, unpublish and delete and writes the automatic entries, the Settings → Redirects screen, the delete/unpublish prompt and the "Old addresses" note.

**Tech Stack:** PHP 8.5, PSR-7/15, PHPUnit 11, Folio's vanilla admin JS (node tests with `tests/js/fake-dom.mjs`). No new dependencies.

**Spec:** `docs/superpowers/specs/2026-11-02-redirects-design.md`

## Global Constraints

- `preflow/core` must not depend on `preflow/routing`; `Application` wires routing classes behind `class_exists`, as it does today.
- Only a thrown `Preflow\Core\Exceptions\NotFoundHttpException` on `GET`/`HEAD` triggers a lookup or a recording; a 404 *response* (Folio's gated page) never does; other methods keep their 404.
- Paths are compared after `LocaleMiddleware` stripped the locale prefix, together with the request's `locale` attribute (the site's default locale when absent). One canonical form everywhere (`RedirectPath::normalize()`, Task 1).
- Status: 301 for automatic (`auto`) and removed-page (`removed`) entries; hand-made (`manual`) entries choose 301 (default) or 302. Every redirect answer carries `Cache-Control: public, max-age=86400` (a renamed-back page must not loop in a browser that cached a 301 forever).
- The original query string is kept on every redirect.
- A record's current address always wins: the middleware only runs on a 404, and `RecordAddresses` deletes any entry at an address a record has just taken.
- Not-found list: path (no query string), locale, count, first and last hit; no IP, user agent or referrer. Limit 1000 rows by default (least recently hit dropped first); single hits hidden in the list; a dismissed address reappears only when hit again 30 or more days after the dismissal.
- Storage failures (a table not migrated yet, a JSON file not writable) never turn a 404 into a 500 and never break a publish or a delete: the redirect pieces catch `\Throwable`, log in debug mode, and carry on.
- Ids of the internal tables are `substr(hash('sha256', implode("\n", $parts)), 0, 32)`; exact lookups go by id (MySQL compares value columns case-insensitively, ids are lowercase hex), every other query re-checks exact equality in PHP as `Publisher::revisionsOf()` does.
- Permission `redirects.manage` guards Settings → Redirects; the automatic entries need no permission; choosing a target while deleting or unpublishing needs only the right to that delete or unpublish.
- Folio texts in `packages/folio/lang/{en,de,fr}/folio.php` under `folio.redirects.*`; German uses "du" like the rest of Folio's German texts.
- Release: 0.52.0 (ROADMAP current-state line, the item "301 redirects on slug change" marked done with an **Upgrading** note: SQL sites run `preflow migrate`, which creates the three tables and records every live page's current addresses; without that run, the first rename of a page after upgrading redirects only for publishing types).

## Review Focus

1. A prefix-mode area entry whose new prefix is `/` and a request like `/old//evil.example/x` → the `Location` must stay a path on this site (`/evil.example/x`), never `//evil.example/x` (Task 1 `RedirectPathTest::test_normalize_collapses_slashes`, Task 2 `test_prefix_area_never_yields_a_protocol_relative_location`).
2. A slug with umlauts (`/de/über-uns`, sent by the browser as `/de/%C3%BCber-uns`) and the same address typed by hand in the Settings form → one entry, found by the request (Task 1 `test_normalize_encodes_each_segment_once`, Task 5 `test_typed_address_with_umlauts_matches_the_browser_form`).
3. A site upgraded but not migrated yet (tables missing) → pages, 404s, publishes and deletes behave exactly as before (Task 2 `test_store_failure_keeps_the_404`, Task 4 `test_publish_works_without_the_tables`).
4. Renaming A → B → A → the page answers at A, B redirects to A, no entry at A remains (Task 4 `test_rename_back_drops_the_entry_and_the_page_wins`).
5. Deleting a record whose earlier addresses already redirect to it, with a target picked → those entries move to the new target too, nothing is left broken (Task 4 `test_delete_with_target_repoints_entries_that_pointed_to_the_record`).

---

### Task 1: The registry and the pure redirect pieces

**Files:**
- Create: `packages/core/src/Http/NonPublicPaths.php`
- Create in `packages/routing/src/Redirect/`: `RedirectPath.php`, `Redirect.php`, `AreaMode.php`, `RedirectStore.php`, `RedirectTargets.php`, `NotFoundLog.php`, `NotFoundFilter.php`, `RedirectConfig.php`
- Create: `packages/skeleton/config/redirects.php` (every key commented, like `config/csrf.php`)
- Test: `packages/core/tests/Http/NonPublicPathsTest.php`, `packages/routing/tests/Redirect/{RedirectPathTest,RedirectTest,NotFoundFilterTest,RedirectConfigTest}.php`

**Interfaces:**
- Produces: `final class NonPublicPaths { public function __construct(); public function add(string $prefix): void; public function matches(string $path): bool; public function all(): array }` — `/--component` is in it from the start; segment-bounded (`/folio` matches `/folio` and `/folio/x`, not `/folios`); a prefix of `''` or `/` is ignored (it would hide the whole site).
- Produces: `final class RedirectPath { public static function normalize(string $path): ?string; public static function under(string $path, string $area): ?string }` — `normalize`: null for a path not starting with `/`, containing control characters, a `..` segment, or longer than 300 characters after normalizing; collapses repeated slashes, drops a trailing slash (not for `/`), decodes then `rawurlencode`s each segment (so `%C3%BC`, `ü` and `%c3%bc` give one form). `under`: the rest (`''` or `/…`) when `$path` equals `$area` or starts with `$area . '/'`, else null.
- Produces: `enum AreaMode: string { case One = 'one'; case Prefix = 'prefix'; }`
- Produces: `final readonly class Redirect { public function __construct(public string $id, public string $path, public string $locale, public bool $area, public AreaMode $mode, public ?string $targetType, public ?string $targetId, public ?string $targetUrl, public int $status) }` — `$locale` `''` means any language; `status` 301 or 302 (anything else → `\InvalidArgumentException`); `Redirect::id(string $kind /* 'exact'|'area' */, string $locale, string $path): string` builds the id (Global Constraints).
- Produces: `interface RedirectStore { public function exact(string $path, string $locale): ?Redirect; /** @return list<Redirect> */ public function areas(string $locale): array; public function hit(Redirect $redirect, \DateTimeImmutable $at): void; }` — `exact()` tries the locale, then `''`; `areas()` returns the locale's and the `''` ones.
- Produces: `interface RedirectTargets { public function url(string $type, string $id, string $locale): ?string; }` — the record's public URL in that locale, else its default-language URL, else null (gone, not public).
- Produces: `interface NotFoundLog { public function record(string $path, string $locale, \DateTimeImmutable $at): void; }`
- Produces: `final class NotFoundFilter { public function __construct(array $ignore = []); public function ignores(string $path): bool }` — built-in prefixes (case-insensitive): `/wp-`, `/wordpress`, `/cgi-bin`, `/xmlrpc`, `/phpmyadmin`, `/pma`, `/vendor/`, `/server-status`, `/.well-known/`, `/autodiscover`, `/ecp/`, `/owa/`, `/boaform`, `/actuator`, `/hnap1`, `/cdn-cgi/`; any segment starting with `.` (`/.env`, `/.git/config`, `/x/.DS_Store`); extensions `.php .asp .aspx .jsp .cgi .env .ini .sql .bak .old .orig .swp .zip .tar .gz .tgz .rar .7z .log .yml .yaml .conf .config .map`. `$ignore` entries starting with `.` add extensions, others add prefixes.
- Produces: `final readonly class RedirectConfig { public bool $recordNotFound = true; public int $notFoundLimit = 1000; public array $ignore = []; public static function fromFile(string $path): self }` — keys `not_found` (bool), `not_found_limit` (int ≥ 1, else `\InvalidArgumentException` naming the key), `ignore` (list of strings); a missing file means the defaults.

- [ ] **Step 1: Write the failing tests**
  - `NonPublicPathsTest`: `test_component_endpoint_is_in_from_the_start`, `test_matches_are_segment_bounded`, `test_root_prefix_is_ignored`.
  - `RedirectPathTest`: `test_normalize_collapses_slashes` (`/old//evil.example/x/` → `/old/evil.example/x`), `test_normalize_encodes_each_segment_once` (`/über-uns`, `/%C3%BCber-uns`, `/%c3%bcber-uns` → `/%C3%BCber-uns`), `test_normalize_refuses_dot_segments_control_characters_and_long_paths` (`/a/../b`, `"/a\x00"`, `/` + 301×`a` → null), `test_root_stays_root`, `test_under` (`/workshops-2024/basel` under `/workshops-2024` → `/basel`; `/workshops-2024` → `''`; `/workshops-2024x` → null).
  - `RedirectTest`: `test_status_must_be_301_or_302`, `test_id_is_32_hex_and_differs_by_kind_locale_and_path`.
  - `NotFoundFilterTest` data provider: ignored `/wp-login.php`, `/.env`, `/x/.git/config`, `/xmlrpc.php`, `/backup.sql`, `/WP-ADMIN/`; kept `/old-page`, `/de/werkstatt`, `/page.html`, `/images/missing.jpg`; a configured `/legacy/` prefix and `.json` extension are ignored.
  - `RedirectConfigTest`: `test_defaults_without_file`, `test_reads_all_keys`, `test_limit_below_one_is_refused`.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/core/tests/Http/NonPublicPathsTest.php packages/routing/tests/Redirect` — FAIL (unknown classes).
- [ ] **Step 3: Implement** the files above and the skeleton `config/redirects.php`.
- [ ] **Step 4: Run** the same command — PASS; then `vendor/bin/phpunit packages/core packages/routing` — PASS.
- [ ] **Step 5: Commit** `feat(routing): redirect entries, stores and the not-found filter; core NonPublicPaths`

### Task 2: `RedirectMiddleware` and its boot

**Files:**
- Create: `packages/routing/src/Redirect/RedirectMiddleware.php`
- Modify: `packages/core/src/Application.php` (bind `NonPublicPaths` in `boot()` before the providers, next to `OldInput`; new `bootRedirects()` called right after `bootMiddleware()`)
- Test: `packages/routing/tests/Redirect/RedirectMiddlewareTest.php`, `packages/core/tests/RedirectBootTest.php`

**Interfaces:**
- Consumes: Task 1, all of it.
- Produces: `final class RedirectMiddleware implements MiddlewareInterface { public function __construct(RedirectStore $store, ?RedirectTargets $targets, ?NotFoundLog $log, NonPublicPaths $nonPublic, NotFoundFilter $filter, string $defaultLocale, \Closure $now /* fn(): \DateTimeImmutable */, ?\Closure $warn = null /* fn(string): void */) }`
- Produces: `Application::bootRedirects()` adds the middleware only when `class_exists(RedirectMiddleware::class)` and the container has a `RedirectStore`; `RedirectTargets` and `NotFoundLog` are taken when bound; `NotFoundLog` is left out when `config/redirects.php` says `'not_found' => false`; the default locale comes from `siteLocales()`; `$warn` as `csrfWarning()` does in debug mode.

Behaviour of `process()` (implement exactly):
1. Call the handler. Anything but a thrown `NotFoundHttpException` passes through untouched.
2. Rethrow when the method is not GET/HEAD, when `NonPublicPaths::matches()` the request path, or when `RedirectPath::normalize()` gives null.
3. Locale: the request's `locale` attribute when a non-empty string, else `$defaultLocale`.
4. Lookup: `exact()`; else among `areas()` the one whose `path` is the longest that `RedirectPath::under()` accepts. Any `\Throwable` from the store: warn, rethrow the 404.
5. Found: the target URL is `targetUrl` (for an area in `Prefix` mode: `rtrim(targetUrl, '/') . rest`, and `'/'` when that is empty), else `RedirectTargets::url(targetType, targetId, entry locale or the request's)`. No URL, or a URL whose path equals the request's own visitor-visible path → rethrow the 404 (counted with `hit()`, not recorded as not found). Else `hit()` (errors swallowed) and answer `status` with `Location` = URL plus the original query (`?` or `&`), `Cache-Control: public, max-age=86400`, empty body. A `Location` starting with `//` or `/\` is never sent: rethrow instead.
6. Not found: unless the log is null or `NotFoundFilter::ignores()` the path, `record()` (errors swallowed with a warning); rethrow.

- [ ] **Step 1: Write the failing tests** (in-memory fakes for the three interfaces): `test_exact_entry_redirects_with_its_status_and_cache_header`, `test_any_language_entry_matches_every_locale`, `test_longest_area_wins`, `test_area_one_target_and_prefix_mode`, `test_prefix_area_never_yields_a_protocol_relative_location` (area `/old`, prefix target `/`, request `/old//evil.example/x` → `Location: /evil.example/x`), `test_query_string_is_kept`, `test_head_is_redirected_post_is_not`, `test_record_target_uses_the_request_locale`, `test_target_without_url_keeps_the_404_and_counts_a_hit`, `test_target_equal_to_the_request_keeps_the_404`, `test_non_public_paths_are_neither_redirected_nor_recorded`, `test_a_404_response_is_left_alone` (handler returns a 404 response → same response, nothing recorded), `test_not_found_is_recorded_without_visitor_data` (the fake log receives exactly path and locale), `test_noise_is_not_recorded`, `test_store_failure_keeps_the_404`, `test_log_failure_keeps_the_404`. `RedirectBootTest`: `test_middleware_is_added_when_a_store_is_bound` (a provider binds an in-memory store; a GET to an unknown path → 301), `test_no_store_no_middleware`, `test_not_found_off_in_config_records_nothing`, `test_site_middleware_runs_first` (a `config/middleware.php` middleware that answers `/legacy` wins over an entry for `/legacy`).
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/routing/tests/Redirect packages/core/tests/RedirectBootTest.php` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same command, then `vendor/bin/phpunit packages/core packages/routing` — PASS.
- [ ] **Step 5: Commit** `feat(routing): answer unfound public pages from a redirect store and record the rest`

### Task 3: Folio's stores, target resolution and the non-public paths

**Files:**
- Create in `packages/folio/src/Redirects/`: `RedirectEntries.php` (implements `RedirectStore`), `NotFoundEntries.php` (implements `NotFoundLog`), `FolioRedirectTargets.php` (implements `RedirectTargets`), `LiveRecords.php`
- Modify: `packages/folio/src/FolioServiceProvider.php` (bind the four when a `DataManager` exists, `NotFoundEntries` with `RedirectConfig::fromFile(config/redirects.php)->notFoundLimit`; add their definitions to `TableSync`'s internal list unconditionally; register `{prefix}` in `NonPublicPaths` when bound and the prefix is not `/`), `packages/members/src/MembersServiceProvider.php` (register its configured path, guarded by `bound(NonPublicPaths::class)` as it does for `CsrfSignInPaths`), `packages/media/src/MediaServiceProvider.php` (register `MediaConfig::$privateUrl`, same guard), `packages/folio/composer.json` (core and routing constraints as `ComposerConstraintsTest` expects for 0.52)
- Test: `packages/folio/tests/Redirects/{RedirectEntriesTest,NotFoundEntriesTest,FolioRedirectTargetsTest}.php`, `packages/folio/tests/Integration/RedirectsTest.php`, one test each in members and media

**Interfaces:**
- Consumes: Task 1 interfaces and `Redirect`, `Redirect::id()`; Task 2 boot (binding `RedirectStore` is what turns it on).
- Produces: tables (internal `TypeDefinition`s in storage `default`, built like `StoredOverrides::definition()`; use the column types `Schema/RecordTableSync.php` supports, an integer type for counts and status):
  - `folio_redirects`: `id, path, locale, area (bool), mode ('one'|'prefix'), target_type, target_id, target_url, status, kind ('auto'|'removed'|'manual'), source_type, source_id, hits, created_at, created_by, last_hit_at`
  - `folio_not_found`: `id, path, locale, hits, first_hit_at, last_hit_at, dismissed_at`
  - `folio_addresses` (written in Task 4): `id, type, record_id, locale, path`
- Produces: `RedirectEntries` beyond the interface: `find(string $id): ?array` (the row), `all(): list<array>`, `save(array $row): void` (computes `id` from area/locale/path; replaces an entry with the same id), `delete(string $id): void`, `targeting(string $type, string $id): list<array>`, `sourcedBy(string $type, string $id): list<array>`, `at(string $locale, string $path): ?array` (the exact entry at that address). Rows are arrays with the column names above.
- Produces: `NotFoundEntries` beyond the interface: `__construct(DataManager $dm, int $limit = 1000)`, `visible(): list<array>` (hits ≥ 2, not dismissed, most hits first, then most recent), `find(string $id): ?array`, `dismiss(string $id, \DateTimeImmutable $at): void`, `forget(string $locale, string $path): void`. `record()`: an existing row gets `hits + 1` and `last_hit_at`; a row dismissed 30 days or more before `$at` is reset (`hits` 1, `first_hit_at` now, `dismissed_at` cleared); a dismissed row within 30 days only gets `last_hit_at`; a new row is inserted with `hits` 1, then rows beyond the limit are deleted, least recent `last_hit_at` first.
- Produces: `LiveRecords::find(string $type, string $id): ?DynamicRecord` — the record as the public site shows it: the published snapshot for publishing types, else the record when it matches the route's `published` field the way `FrontendResolver::match()` queries it (`where(field, value)`), else null; null for types without a frontend route.
- Produces: `FolioRedirectTargets::url()` = `FrontendResolver::urlFor(live, locale) ?? urlFor(live, type default)` on `LiveRecords::find()`.

- [ ] **Step 1: Write the failing tests**
  - `RedirectEntriesTest` (JSON storage in a temp dir and SQLite, as other Folio storage tests do): `test_exact_tries_the_locale_then_any_language`, `test_areas_returns_both`, `test_hit_counts_and_stamps`, `test_save_replaces_the_same_address`, `test_lookups_are_exact_on_case` (`/Old` stored, `/old` asked → null on SQLite and JSON).
  - `NotFoundEntriesTest`: `test_first_hit_then_count`, `test_single_hits_are_hidden`, `test_limit_drops_least_recent`, `test_dismissed_stays_hidden_within_30_days_and_returns_after`, `test_rows_hold_no_visitor_data` (stored keys are exactly the table's columns).
  - `FolioRedirectTargetsTest`: `test_url_in_the_requested_language`, `test_falls_back_to_the_default_language`, `test_unpublished_or_deleted_target_gives_null`, `test_non_publishing_type_respects_its_published_field`.
  - `Integration/RedirectsTest` (a Folio site fixture with a `page` type, `de`/`en`, prefix strategy): `test_manual_entry_redirects_an_unknown_path`, `test_a_page_wins_over_an_entry_at_its_address`, `test_gated_404_is_not_recorded`, `test_folio_admin_404_is_not_recorded`, `test_unknown_public_path_is_recorded_once_per_hit`.
  - Members `test_members_path_is_not_public`; media `test_private_files_path_is_not_public`.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/folio/tests/Redirects packages/folio/tests/Integration/RedirectsTest.php` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the Step 2 command, then `vendor/bin/phpunit packages/folio packages/members packages/media` — PASS.
- [ ] **Step 5: Commit** `feat(folio): redirect, not-found and address stores; old addresses answer`

### Task 4: Automatic redirects (the address book) and the removal target

**Files:**
- Create: `packages/folio/src/Redirects/RecordAddresses.php`, `packages/folio/src/Redirects/RemovalTarget.php`
- Modify: `packages/folio/src/Publishing/Publisher.php` (new last constructor parameter `?RecordAddresses $addresses = null`; after `writeSnapshot()` call `sync(type, id, $previous)`; `unpublish()` takes `?RemovalTarget $target = null` and calls `removed()`; `forget()` stays as it is, because `RecordDeleter` records the removal for every type), `packages/folio/src/Http/AdminController.php` (`saveWithBlocks()` and `confirmTranslation()`'s non-publishing save call `sync()`; `destroy()`/`bulkDelete()` read `_redirect_to`), `packages/folio/src/Content/RecordDeleter.php` (`delete(string $type, array $ids, ?RemovalTarget $target = null)`; after the writes, `removed()` for every deleted record, cascades included), `packages/folio/src/Content/FolioTextSource.php` (after its non-publishing save at line ~271: `sync()`), `packages/folio/src/Http/PublishingController.php` (`unpublish()` reads `_redirect_to`), `packages/folio/src/FolioServiceProvider.php` (bind `RecordAddresses`, pass it to `Publisher`; `afterMigrate('folio.addresses', …)` → `RecordAddresses::seed()`)
- Test: `packages/folio/tests/Redirects/RecordAddressesTest.php`, additions to `packages/folio/tests/Publishing/PublisherTest.php` and `packages/folio/tests/Content/RecordDeleterTest.php`, `packages/folio/tests/Integration/AutomaticRedirectsTest.php`

**Interfaces:**
- Consumes: Task 3 `RedirectEntries`, `LiveRecords`, the `folio_addresses` table; `FrontendResolver::pathFor()`.
- Produces: `final readonly class RemovalTarget { public static function parse(mixed $posted): ?self /* "type:id" of a type with a frontend route, or an http(s) URL / path on this site; anything else null */; public ?string $type; public ?string $id; public ?string $url }`
- Produces: `final class RecordAddresses { public function sync(string $type, string $id, ?DynamicRecord $previousLive = null): bool /* true when the record has just left the site */; public function removed(string $type, string $id, ?RemovalTarget $target): void; public function of(string $type, string $id): array /* locale => path, stored */; public function seed(): list<string> /* migrate lines */ }`

Rules of `sync()` (implement exactly; "current" = `pathFor()` per locale of `LiveRecords::find()`, "stored" = the `folio_addresses` rows, or `$previousLive`'s paths when there are none):
1. Every current address: delete the entry at it (`RedirectEntries::at()`; the page wins), store the address row.
2. Record has at least one current address: every stored address that is no longer current becomes an `auto` entry, exact, the stored locale and path, target this record, source this record, 301; its address row is removed. Entries whose source is this record and whose target is empty (an earlier untargeted removal) get this record as target and kind `auto`.
3. Record has no current address but had stored ones: same as `removed($type, $id, null)`; return true.
- `removed()`: every stored address of the record and every entry targeting the record become `removed` entries (exact, 301, source this record) with the target's type/id or URL, or no target when `$target` is null; the address rows are deleted.
- `seed()`: for every type with a frontend route, every live record without address rows gets them (no entries are written); returns one line with the count.
- All of it inside `try/catch (\Throwable)`: a failure is logged and the publish/save/delete stands.

- [ ] **Step 1: Write the failing tests**
  - `RecordAddressesTest`: `test_first_sync_only_records`, `test_rename_writes_an_auto_entry_to_the_record`, `test_rename_twice_both_old_addresses_point_to_the_record` (no chain: both entries target the record, not each other), `test_rename_back_drops_the_entry_and_the_page_wins`, `test_cleared_translated_slug_redirects_that_language_to_the_default` (entry for `de` exists, `FolioRedirectTargets::url(…, 'de')` gives the default-language URL), `test_previous_live_is_used_when_nothing_is_stored`, `test_leaving_the_site_without_target_writes_untargeted_removed_entries`, `test_coming_back_repoints_its_untargeted_entries`, `test_removed_with_url_target`, `test_seed_records_addresses_without_entries`.
  - `PublisherTest`: `test_publish_with_a_new_slug_redirects_the_old_one`, `test_scheduled_publish_redirects_too` (through `Scheduler::runDue()`), `test_restored_revision_published_redirects`, `test_unpublish_with_target`, `test_publish_works_without_the_tables` (the addresses table missing → publish succeeds).
  - `RecordDeleterTest`: `test_delete_with_target_redirects_the_record_and_its_cascades`, `test_delete_with_target_repoints_entries_that_pointed_to_the_record`, `test_delete_without_target_leaves_404s`.
  - `Integration/AutomaticRedirectsTest`: `test_non_publishing_save_with_new_slug_redirects`, `test_non_publishing_status_change_takes_it_off_and_flashes` (the save's redirect back carries a `warning` flash naming "Send its old links to…" with a link to Settings → Redirects filtered to the record), `test_bulk_delete_posts_one_target_for_all`, `test_old_address_answers_301_end_to_end` (publish, rename, publish, GET old URL → 301 to the new one, `de` too).
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/folio/tests/Redirects packages/folio/tests/Publishing packages/folio/tests/Content/RecordDeleterTest.php packages/folio/tests/Integration/AutomaticRedirectsTest.php` — FAIL.
- [ ] **Step 3: Implement.** The flash link of a non-publishing removal is `{prefix}/redirects?record={type}:{id}` (Task 5 filters by it); show it only to users with `redirects.manage`, others get the flash without the link.
- [ ] **Step 4: Run** the Step 2 command, then `vendor/bin/phpunit packages/folio` — PASS.
- [ ] **Step 5: Commit** `feat(folio): renamed and removed pages redirect automatically`

### Task 5: Settings → Redirects

**Files:**
- Create: `packages/folio/src/Http/RedirectsController.php`, `packages/folio/templates/admin/redirects/index.twig` (two tabs), `packages/folio/templates/admin/redirects/form.twig`
- Modify: `packages/folio/src/Routing/FolioRoutes.php`, `packages/folio/src/Http/FolioPermissionMiddleware.php`, `packages/folio/src/FolioServiceProvider.php` (`declare('redirects.manage', 'Redirects', 'Folio')`; nav item in `settings`, label `folio.nav.redirects`, order 930), `packages/folio/lang/{en,de,fr}/folio.php`
- Test: `packages/folio/tests/Http/RedirectsControllerTest.php`, `packages/folio/tests/Routing/FolioRoutesTest.php` (routes before `{type}`)

**Interfaces:**
- Consumes: Task 3 stores, Task 4 `RemovalTarget` (the form's target field uses the same `"type:id"` / URL format), `NonPublicPaths`, `FrontendResolver`.
- Produces routes (literal, before the generic `{type}` routes, like `/translations`), all `redirects.manage` except `_targets`:
  - `GET {p}/redirects` `index` (`?tab=not-found`, `?q=` search on address, `?record=type:id` filter)
  - `GET {p}/redirects/new` `create` (`?not_found={id}` prefills the address)
  - `POST {p}/redirects` `store`; `GET {p}/redirects/{id}` `edit`; `POST {p}/redirects/{id}` `update`; `POST {p}/redirects/{id}/delete` `destroy`
  - `POST {p}/redirects/not-found/{id}/dismiss` `dismiss`
  - `GET {p}/redirects/_targets?q=` `targets`: JSON `{items: [{value: "type:id", label, type, url}]}`, at most 20, live records of types with a frontend route whose label matches; guarded the way `_relation/{type}` is (any signed-in staff user: it lists public pages only).
- The form: address as the visitor sees it (`/de/werkstatt`; a first segment that is one of the site's non-default locales becomes the entry's locale, else the default locale; a single-language site stores `''`), "and everything under it" (area), for an area "send all to one target" or "keep the rest under a new address", target (page picker using `_targets`, or a URL / path), permanent (301, default) or temporary (302).
- Refused with a message on the field: an address `RedirectPath::normalize()` rejects; a non-public address; an address a page currently has (`FrontendResolver::match()` finds it: "A page has this address; it always wins."); a target equal to the address; a URL target that is itself an entry's address ("That address redirects already; pick its target instead."); prefix mode without a URL/path target; a URL that is not `http(s)://…` or a path starting with a single `/`.
- The list shows per entry: address (visitor-visible), language, target (record label + its URL, or the URL; "no target" for an untargeted removal; "broken" when a record target resolves to null), kind, 301/302, hits, created. Untargeted and broken entries first, then newest.
- The Not found tab: `NotFoundEntries::visible()` with address, language, hits, first/last hit, a "Redirect…" link (`/redirects/new?not_found={id}`) and "Dismiss". Saving an entry made from a not-found row removes that row.

- [ ] **Step 1: Write the failing tests** — `test_index_lists_entries_with_targets_and_broken_flags`, `test_index_filters_by_record`, `test_not_found_tab_hides_single_hits`, `test_create_exact_entry_with_page_target`, `test_create_area_entry_prefix_mode`, `test_typed_address_with_umlauts_matches_the_browser_form` (typed `/de/über-uns` → a GET `/de/%C3%BCber-uns` is redirected), `test_locale_prefix_becomes_the_entry_language`, `test_address_of_a_live_page_is_refused`, `test_target_that_redirects_already_is_refused`, `test_redirect_from_not_found_removes_the_row`, `test_dismiss_hides_the_row`, `test_targets_endpoint_lists_only_public_pages`, `test_screen_needs_redirects_manage` (403 for a user without it; `_targets` 200 for any staff user), `test_nav_item_only_with_the_permission`.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/folio/tests/Http/RedirectsControllerTest.php packages/folio/tests/Routing` — FAIL.
- [ ] **Step 3: Implement**, following the Translations screen's controller and template structure and Folio's existing list styles; texts in en/de/fr.
- [ ] **Step 4: Run** the Step 2 command, then `vendor/bin/phpunit packages/folio` — PASS (`TemplatesExistTest`, `TranslationsDeclarationTest` included).
- [ ] **Step 5: Commit** `feat(folio): Settings → Redirects with the not-found list`

### Task 6: The delete/unpublish prompt, the "Old addresses" note, docs

**Files:**
- Modify: `packages/folio/src/Content/RecordDeleter.php` (`summary()` adds `addresses: int`, the count of live addresses of the plan's deleted records, cascades included), `packages/folio/src/Http/AdminController.php` (editor context: `old_addresses`, the entries targeting the record, and the unpublish button's address count), `packages/folio/assets/admin.js` (the prompt), `packages/folio/templates/admin/form.twig` (sidebar note; unpublish form gets the prompt), `packages/folio/templates/admin/publishing/*` where the unpublish button lives, `packages/folio/lang/{en,de,fr}/folio.php`
- Modify docs: `packages/routing/README.md` (Redirects: the middleware, the interfaces, `config/redirects.php`, a store without Folio), `packages/core/README.md` (`NonPublicPaths`), `packages/folio/README.md` (automatic redirects, the prompt, Settings → Redirects, permission), `packages/members/README.md` and `packages/media/README.md` (one line each: their paths are non-public), `ROADMAP.md` (item done with the **Upgrading** note from Global Constraints; current-state line `**v0.52.0** — N tests` after the full run)
- Test: `packages/folio/tests/js/removal-prompt.test.mjs`, `packages/folio/tests/Content/RecordDeleterTest.php` (`test_summary_counts_addresses_including_cascades`), `packages/folio/tests/Http/…` (`test_editor_shows_old_addresses`)

**Interfaces:**
- Consumes: Task 4 `_redirect_to` (posted by the forms), Task 5 `_targets` endpoint.
- The prompt: when the delete plan says `addresses > 0` (or the unpublish form's `data-removal-addresses` is above 0), a `<dialog>` replaces `window.confirm`: the plan's lines, "Send its old links to…" with a search field over `_targets` (choose one result) or a URL field, "Leave them as not found" as the default, and the confirm/cancel buttons; on confirm a hidden `_redirect_to` (`type:id` or the URL) is added to the form before it is sent. With `addresses` 0 or the plan unavailable, today's `confirm()` behaviour stays exactly as it is. A restricted plan still only shows the refusal.
- The note: in the editor sidebar, "Old addresses" with each entry's visitor-visible address (at most 10, then "+N"), and a link to `{p}/redirects?record={type}:{id}` for users with `redirects.manage`; hidden when there are none.

- [ ] **Step 1: Write the failing tests** — JS: `test_no_addresses_keeps_the_plain_confirm`, `test_addresses_open_the_dialog_and_post_the_chosen_page`, `test_url_target_is_posted`, `test_leave_as_not_found_posts_no_target`, `test_restricted_plan_only_alerts`. PHP: `test_summary_counts_addresses_including_cascades`, `test_editor_shows_old_addresses`, `test_unpublish_form_carries_the_address_count`.
- [ ] **Step 2: Run** `node --test packages/folio/tests/js/` and `vendor/bin/phpunit packages/folio` — FAIL.
- [ ] **Step 3: Implement**; write the READMEs and the ROADMAP note.
- [ ] **Step 4: Run** `node --test packages/folio/tests/js/` and the full suite `vendor/bin/phpunit` — PASS (record the test count in the ROADMAP line).
- [ ] **Step 5: Commit** `feat(folio): choose where a removed page's links go; old addresses in the editor; redirects docs`
