# Page cache (performance step 3) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Anonymous GETs are served as finished pages from a cache. Personal parts are filled in live, and every write
refreshes the pages it touched.

**Architecture:**
- **Data layer.** It reports reads and writes to a core `PageCache` service. Reads become dependency tags of the page
  being rendered. Writes invalidate pages by tag and queue rebuilds.
- **Serving.** A middleware placed before the session middleware serves hits from one file per page and stores
  misses.
- **Live parts and per-request values.** Live parts are stored as signed placeholders and rendered per visit.
  Per-request values (nonce, CSRF, site-registered values) are swapped in.
- **Rebuilding.** Rebuilds run after the response is sent, within a time budget, and through a cron command.

**Tech Stack:** PHP 8.4+, PDO SQLite for the tag index (already used by sites), PHPUnit 11.5 (root `vendor/bin/phpunit`).

**Spec:** `docs/superpowers/specs/2026-11-08-page-cache-design.md`. Calls 1–10 and "Left to the builder" bind; read them first.

## Global Constraints

- **Correctness first.** After any write through the data layer, no visitor gets a page that showed the old state,
  except where a developer chose next visit or timed. Personal data never reaches another visitor.
- **Defaults (configurable per site in `config/page_cache.php`):**
  - `enabled` false on upgrade, true in the skeleton;
  - `max_age` 14400 s (4 h);
  - `rebuild_budget` 2.0 s;
  - `cache_query` true;
  - `ignore_query` `['utm_*', 'fbclid', 'gclid', 'mc_cid', 'mc_eid']`;
  - `vary_headers` `['HX-Request', 'HX-Target']`;
  - `signed_in_cookies` = the auth marker cookie plus every guard's remember cookie.
- **The cache is off whenever** the live `app.debug` is on, in the console, or a request is an internal render that
  isn't a rebuild.
- **Never store:**
  - non-GET/HEAD;
  - anything but 200;
  - a response with `Set-Cookie`;
  - a request where any guard resolved a user;
  - a request carrying a signed-in cookie;
  - an `HX-History-Restore-Request` stored as a fragment (it is a full page).
- **Hit path:** one file read and no database query. Its PHP time over boot is measured like steps 1–2.
- No new runtime dependency. Public provider interfaces stay.
- Release 0.60.0 with a ROADMAP entry and a before/after table. Then the 5 sites, each: backed up, suites serial and
  parallel, deployed.
- Run test suites one at a time, never two at once. Tests use temp base paths, never the monorepo root.
- Crontab edits on the server: write a file and verify the full job count; never pipe into `crontab -`.

## Review Focus

1. **A write while a page renders.** Page rendered with old data, write invalidates, then the render stores its
   now-stale page. Expected: the store is refused, because a tag of the page was invalidated after the render
   started. Test in Task 3.
2. **Two anonymous visitors, one with holz's guest-album cookie.** Expected: neither sees the other's album, tokens or
   nonce. Test in Task 4.
3. **A 404 for a URL whose record is created a minute later** (a new job slug). Expected: the 404 was never cached, so
   the page appears at once. Test in Task 3.
4. **Locale.** `/de/x` and `/fr/x` are different entries. An unprefixed URL that redirects by Accept-Language is never
   cached. Test in Task 3.
5. **A live block that throws at serve time.** Expected: that block renders its error boundary and the rest of the
   page is served, never a 500. Test in Task 4.

---

### Task 1: Page store, keys and tag index

**Files:**
- Create in `packages/core/src/PageCache/`:
  - `PageCacheConfig.php`;
  - `PageKey.php`;
  - `PageStore.php`;
  - `TagIndex.php`;
  - `Freshness.php`;
  - `PageCacheSource.php`.
- Tests: `packages/core/tests/PageCache/{PageKeyTest,PageStoreTest,TagIndexTest}.php`.

**Interfaces (produces):**
- `PageCacheConfig::fromArray(array $config): self` exposes:
  - `enabled`, `maxAge`, `rebuildBudget`, `cacheQuery`, `ignoreQuery`, `varyHeaders`, `signedInCookies`.
- `PageKey::fromRequest(ServerRequestInterface $r, PageCacheConfig $c): ?PageKey` returns null when not cacheable by
  shape. It has:
  - `->id(): string` (sha1 of host + path + normalised query + the vary header values);
  - `->url(): string`;
  - `->isFragment(): bool`;
  - `->hasQuery(): bool`.
- `enum Freshness { case Fresh; case NextVisit; case Timed; }`. Timed carries its seconds in the tag record, not the
  enum.
- `PageStore` (files under `storage/cache/pages/{releaseKey}/{id[0..1]}/{id}.page`):
  - `get(PageKey): ?StoredPage`, where `StoredPage` has `body`, `headers`, `createdAt`, `expiresAt`, `stale`;
  - `put(PageKey, StoredPage)`, atomic via tmp + rename;
  - `forget(string $id)`;
  - `markStale(string $id)`;
  - `clear(): int`.
- `releaseKey()` = a hash of `realpath(basePath)` plus the boot-manifest or `vendor/composer/installed.json` mtime.
  `PageStore` prunes other release directories older than 24 h on its first write in a process.
- `TagIndex` (SQLite at `storage/cache/pages/{releaseKey}/index.sqlite`, WAL):
  - tables `pages(id, url, fragment, has_query, created_at)` and `tags(tag, page_id, freshness, timed_seconds)`;
  - `record(string $pageId, string $url, bool $fragment, bool $hasQuery, array $tags)`, where `$tags` is
    `tag => [Freshness, ?int seconds]`;
  - `pagesFor(array $tags): array<string, Freshness>`, giving the strictest freshness per page;
  - `forget(string $pageId)`;
  - `invalidatedSince(array $tags, float $since): bool`, backed by an `invalidations(tag, at)` table pruned after 1 h.
- `PageCacheSource` implements `Preflow\Core\Cache\CacheSource` with key `pages`, so `cache:clear` and Folio's Cache
  screen list and clear it.

- [ ] **Tests:**
  - `PageKeyTest`:
    - the query is sorted, and `ignore_query` patterns are dropped, including `utm_*` globbing;
    - `cacheQuery=false` gives null for URLs with a query;
    - `HX-Request` + `HX-Target` values change the id;
    - `HX-History-Restore-Request` gives a full-page key, `isFragment()` false;
    - POST gives null;
    - `/de/x` ≠ `/fr/x`.
  - `PageStoreTest`:
    - put/get round trip with headers;
    - a stale mark survives a reread;
    - a corrupt file gives null (not an exception);
    - a different `releaseKey` doesn't see the page;
    - `clear()` counts.
  - `TagIndexTest`:
    - the strictest freshness wins per page (Fresh > NextVisit > Timed);
    - `invalidatedSince` is true only for invalidations after `$since`;
    - `forget` removes the tags.
- [ ] Implement, run the core suite, and commit: `feat(core): page cache store, keys and tag index`.

### Task 2: Data layer reports reads and writes

**Files:**
- Create:
  - `packages/data/src/Observe/DataObserver.php` (interface);
  - `packages/data/src/Observe/SqlTables.php`;
  - `packages/core/src/PageCache/DependencyRecorder.php`;
  - `packages/core/src/PageCache/ChangeLog.php`.
- Modify:
  - `packages/data/src/DataManager.php`: every read and write method, plus `raw()` and `execute()`;
  - the place where `Application::bootDataLayer()` binds the observer.
- Tests:
  - `packages/data/tests/Observe/{SqlTablesTest,DataManagerObserverTest}.php`;
  - `packages/core/tests/PageCache/{DependencyRecorderTest,ChangeLogTest}.php`.

**Interfaces:**
- `DataObserver`:
  - `read(string $storage, string $type, ?string $id): void`, where `$id` null means a listing;
  - `wrote(string $storage, string $type, ?string $id): void`, where `$id` null means a bulk or unknown write;
  - `rawRead(string $storage, string $sql)`;
  - `rawWrote(string $storage, string $sql)`.
  - `DataManager::observe(DataObserver $o)` (setter), with a no-op default.
- `SqlTables::of(string $sql): ?list<string>`:
  - tables named after FROM, JOIN, UPDATE, INTO, DELETE FROM, with backticks stripped;
  - null when a subquery or CTE makes it unsure.
- **Tags:** `{storage}:{type}` for a listing, `{storage}:{type}#{id}` for a record, `{storage}:*` for an unsure raw
  query.
  - A write of record X invalidates `…#X`, `{storage}:{type}` and `{storage}:*`.
  - A bulk write invalidates every tag starting `{storage}:{type}` and `{storage}:*`.
- `DependencyRecorder` (core):
  - `start(): void`;
  - `stop(): array<string, array{Freshness, ?int}>`;
  - `within(Freshness $f, ?int $seconds, \Closure $fn): mixed`. Reads inside it are tagged with that freshness; the
    default is Fresh.
  - It records only between start and stop.
- `ChangeLog` (core): `changed(array $tags): void` merges into one pending set per process and calls
  `Refresher::invalidate()` once at the end of the request, or of the command.
- `Refresher` (core, created here, `packages/core/src/PageCache/Refresher.php`):
  - `invalidate(array $tags): void`. In this task it forgets every page carrying one of the tags (`TagIndex::pagesFor`,
    then `PageStore::forget` + `TagIndex::forget`) and records the invalidation for `invalidatedSince`.
  - Task 5 adds the freshness rules, and Task 6 adds the queue.
- `PageCache` (core facade service, created here, `packages/core/src/PageCache/PageCache.php`):
  - `changed(string ...$tags): void`, the public call for writes that bypass the data layer (spec call 1). It is
    documented in the core README.
  - Tasks 3 and 5 add to it.

- [ ] **Tests:**
  - `SqlTablesTest`, using holz-style SQL:
    - ``SELECT … FROM `object__published` o JOIN `member` m ON …`` gives both tables;
    - `UPDATE x JOIN y SET …` gives `[x, y]`;
    - `WITH …` gives null.
  - `DataManagerObserverTest`:
    - `find` reports a record read;
    - `query()->get()` and `findMany` report a listing;
    - `saveType`/`save`/`delete` report a record write;
    - `execute('UPDATE …')` reports a raw write;
    - the publisher's copy into `__published` is reported, by publishing a record and asserting it.
  - `ChangeLogTest`: 300 writes of one type in a loop give one merged invalidation call.
- [ ] Implement, run the data, core and folio suites one at a time, and commit:
  `feat(data): report reads and writes to an observer` and `feat(core): page dependencies and change log`.

### Task 3: Serving and storing

**Files:**
- Create:
  - `packages/core/src/PageCache/PageCacheMiddleware.php`;
  - `packages/core/src/PageCache/PerRequestValues.php`.
- Modify: `PageCache` (adds the request state the middleware and the live parts share).
- Modify:
  - `Application.php`: a `bootPageCache()` that adds the middleware right after the locale middleware and before
    `bootSession()`'s middleware, and binds the services;
  - `packages/auth/src/SessionGuard.php`: the marker cookie at login, `restore()` and logout;
  - `packages/skeleton` config (`page_cache.php` with `enabled => true`).
- Tests:
  - `packages/core/tests/PageCache/PageCacheMiddlewareTest.php`;
  - `packages/folio/tests/Integration/PageCacheAppTest.php` (web mode, temp base path).

**Interfaces:**
- `PerRequestValues`:
  - `register(string $name, \Closure $value): void`;
  - `toPlaceholders(string $body): string`, which replaces this request's actual values with
    `<!--pc:v:{name}:{sig}-->`;
  - `fill(string $body): string`.
  - Built in: `nonce` (the request's CSP nonce) and `csrf` (every token value issued in the request).
  - Sites register their own, e.g. holz's `FormToken`.
- The marker cookie is `preflow_auth` (value `1`, path `/`, HttpOnly, SameSite=Lax). It is set at login and at
  restore-from-remember, and cleared at logout.
- **The middleware, step by step:**
  - Compute the key. If it is null, or a signed-in cookie is present, pass through.
  - On a hit that is fresh, or stale/expired (serve it and queue a rebuild of this URL, Task 6): fill the values and
    live parts (Task 4) and return 200 with the stored headers and `X-Page-Cache: hit|stale`.
  - On a miss: `DependencyRecorder::start()`, pass through, `stop()`. Then store only if every "never store" rule
    passes and `!TagIndex::invalidatedSince(tags, renderStart)`. Add `X-Page-Cache: miss`.
- [ ] **Tests:**
  - **`test_the_approval_mail_case`.** An anonymous GET of an object page and its category list is cached (`hit` on
    the second GET). Staff then publish a change to the object. The next anonymous GETs of both show the new title,
    with no stale step.
  - **`test_a_write_during_render_refuses_the_store`.** Simulated by invalidating a tag between `start()` and the
    store. This is Review Focus 1.
  - **`test_404_and_redirects_are_never_cached`.** This is Review Focus 3.
  - **`test_locale_prefixes_are_separate_and_negotiated_redirects_uncached`.** This is Review Focus 4.
  - **`test_signed_in_requests_bypass`**, covering the marker cookie and a remember cookie. Also: a leftover session
    cookie alone does not bypass, and no session is opened on a hit.
  - **`test_responses_setting_cookies_are_not_stored`.**
  - **`test_nonce_and_csrf_are_fresh_per_hit`.** Two hits have different nonces in body and header, and a form token
    from a hit validates on POST.
  - **`test_a_registered_site_value_is_swapped`.**
  - **`test_htmx_fragment_and_full_page_are_separate_entries`.**
  - **`test_head_is_served_from_cache_without_body`.**
  - **`test_disabled_in_debug_and_console`.**
- [ ] Implement, measure the hit path on langcorner.dev.smyr with `hey` (cache on in a temp config; leave its git status
  clean), run the core, auth and folio suites one at a time, and commit:
  `feat(core): serve anonymous pages from the page cache` and
  `feat(auth): a signed-in marker cookie for caches`.

### Task 4: Live parts

**Files:**
- Create:
  - `packages/core/src/PageCache/LivePlaceholder.php` (signing with `app.key`);
  - `packages/components/src/Live.php` (`#[\Attribute(TARGET_CLASS)]`);
  - `packages/components/src/PrefetchesLive.php` (interface);
  - `packages/twig/src/LiveTokenParser.php` + node;
  - Blade `@live`/`@endlive` in `packages/blade/src/BladeEngine.php`.
- Modify: `ComponentRenderer` (wraps live components), `PageCacheMiddleware` (fills live parts).
- Tests:
  - `packages/twig/tests/LiveTagTest.php`;
  - `packages/blade/tests/LiveDirectiveTest.php`;
  - `packages/components/tests/LiveComponentTest.php`;
  - additions to `PageCacheAppTest`.

**Interfaces:**
- **Twig:** `{% live with {number: object.number} %}…{% endlive %}`.
  - The body compiles to a named block of the template.
  - It sees only the `with` values (scalars and arrays) plus globals.
  - When rendered during a cacheable request, it emits its output wrapped in `<!--pc:l:{sig}-->…<!--/pc:l-->`, with
    the recipe (template, block, `with` values) in the signed marker.
  - **Blade:** `@live(['number' => $n]) … @endlive`, with the same contract.
- **Components:** a component class with `#[Live]` is wrapped the same way. Its recipe is the class plus its props.
- **Batching:** before filling, placeholders are grouped by component class. A class implementing
  `PrefetchesLive::prefetchLive(list<array> $propsList): void` gets one call per group.
- **Store and fill:** at store time, the wrapped regions become bare signed placeholders. At fill time, each one is
  rendered through the normal renderer (error boundary included). An unsigned or forged marker is left as an HTML
  comment and never rendered.
- **Double render:** with `app.debug` on, or the `page_cache.verify` config true (the default in tests), the first
  store of a key renders once more as a different anonymous visitor (different `REMOTE_ADDR`, no cookies). It compares
  the two after placeholders are applied. Any difference refuses the store and logs
  `page cache: {url} differs per visitor outside live parts`.

- [ ] **Tests:**
  - `test_two_visitors_never_see_each_others_live_parts`. Guest A has album cookie [1, 2] and guest B has [3]. The same
    cached page shows each their own count. This is Review Focus 2.
  - `test_live_block_sees_only_its_with_values`.
  - `test_live_components_of_one_class_are_prefetched_once`: 24 placeholders give one `prefetchLive` call.
  - `test_a_forged_marker_in_user_text_is_not_rendered`.
  - `test_a_throwing_live_block_renders_its_boundary_and_the_page_is_served`. This is Review Focus 5.
  - `test_a_personal_value_outside_live_parts_is_not_stored_and_logged`.
  - Blade and Twig parity.
- [ ] Implement, run the components, twig, blade, core and folio suites one at a time, and commit:
  `feat(view): live parts in cached pages`.

### Task 5: Freshness dial, expiry and safety maximum age

**Files:**
- Create:
  - `packages/twig/src/FreshnessTokenParser.php` + node;
  - `packages/components/src/Freshness.php` (an attribute taking `Preflow\Core\PageCache\Freshness` and seconds);
  - Blade `@freshness`/`@endfreshness` and `@expires`.
- Modify: `DependencyRecorder` (expiry), `Refresher::invalidate()` (the freshness rules below), `PageCacheMiddleware`
  (the max age).

**Interfaces:**
- **Twig:**
  - `{% freshness 'next-visit' %}…{% endfreshness %}` and `{% freshness 'timed' every '12h' %}…{% endfreshness %}`.
    Reads inside are tagged accordingly. Durations accept `30m`, `12h` and `2d`.
  - `{% expires at someDate %}` and `{% expires in '1h' %}` set the page's `expiresAt`, taking the earliest.
- **Components:** `#[Freshness(Freshness::NextVisit)]` or `#[Freshness(Freshness::Timed, seconds: 43200)]`, applied
  around the component's resolve and render.
- **PHP:** `PageCache::within(Freshness, ?int $seconds, \Closure)` and `PageCache::expiresAt(\DateTimeInterface)`.
- **On a write:**
  - pages whose strictest tag freshness is Fresh are forgotten and queued for rebuild;
  - NextVisit pages are marked stale;
  - Timed tags are ignored. Their pages get `expiresAt = min(expiresAt, now + seconds)` at store time.
- **On a hit:** `createdAt + maxAge` or `expiresAt` in the past means stale. Serve it and queue a rebuild.

- [ ] **Tests:**
  - `test_job_detail_with_other_jobs_next_visit`, the owner's example. A new job of the company leaves the detail page
    a hit but stale. The next visitor gets the old list, and the visit after gets the new list.
  - `test_timed_dependency_ignores_writes_until_its_time`, using a pinned clock seam.
  - `test_max_age_four_hours_serves_stale_and_rebuilds`.
  - `test_expires_at_an_event_date`.
  - `test_the_strictest_freshness_wins_when_a_tag_is_read_twice`.
- [ ] Implement, run the suites, and commit: `feat(view): per-dependency freshness and page expiry`.

### Task 6: Refreshing, commands, deploys and Folio

**Files:**
- Create:
  - `packages/core/src/PageCache/RefreshQueue.php` (a table in the same `index.sqlite`);
  - `packages/devtools/src/Command/PagesWorkCommand.php`;
  - `packages/devtools/src/Command/PagesRefreshCommand.php`.
- Modify:
  - `Refresher` (adds queueing and `work()`);
  - `Application.php` (`afterResponse(\Closure)`, run after `finishResponse()` in `run()`, and run by `handle()` for
    in-process callers only in tests through a seam);
  - Folio `CacheController` + view + lang en/de/fr ("Refresh a page" with a URL field, "Refresh everything");
  - core README, devtools README.

**Interfaces:**
- `Refresher::invalidate(array $tags): void`. Fresh pages are forgotten and the full-page, non-query ones are queued
  with priority: record-tag matches first. Query and fragment pages are only forgotten (spec call 4). NextVisit pages
  are marked stale.
- `Refresher::work(float $budgetSeconds): int` rebuilds queued URLs. Each is an internal anonymous GET marked
  `InternalRender` plus a `PageCache::REBUILD` attribute that allows the store.
  - Rebuilds are deduplicated, and a URL is skipped if its page is already fresh.
- `RefreshQueue` uses claim-with-lease locking, so the after-response work and the cron never build the same URL at
  once.
- **Commands:**
  - `preflow pages:work [--budget=50]`, for cron every minute;
  - `preflow pages:refresh {url}` and `preflow pages:refresh --all` (forget all, queue every known non-query full
    page).
- After a deploy, the new release key starts empty, and `pages:work` warms the URLs of the previous release's index,
  copied when the new index is first opened.

- [ ] **Tests:**
  - `test_after_response_rebuilds_within_budget_and_leaves_the_rest_queued`.
  - `test_cron_work_and_after_response_never_build_one_url_twice`.
  - `test_a_bulk_import_queues_each_page_once`. 300 jobs saved through a command give one queue entry per affected
    page.
  - `test_query_and_fragment_pages_are_forgotten_not_queued`.
  - `test_a_new_release_starts_empty_and_warms_from_the_previous_index`.
  - `test_folio_refresh_page_and_everything`, which needs the `folio.cache` permission.
  - `test_cache_clear_pages_clears`.
- [ ] Implement, run the core, devtools and folio suites, then the full `vendor/bin/phpunit` once, and commit:
  `feat(core): page refresh queue and rebuilds`, `feat(devtools): pages:work and pages:refresh`, and
  `feat(folio): refresh pages from the Cache screen`.

### Task 7: Release 0.60.0 and the sites (controller)

- [ ] Measure before, on 0.59, and after, on languagecorner (/home, /classes, 404, robots.txt) and holz (home, a
  category list, an object, /album, a gallery htmx fragment), locally and live.
- [ ] Write the ROADMAP entry, run the full suite, tag, push, and check both split runs and Packagist.
- [ ] For each site:
  - back up;
  - pin `^0.60`;
  - add `config/page_cache.php` with `enabled => true`;
  - audit its personal parts and move them into `{% live %}` or `#[Live]`, or register them as per-request values.
    holz: the guest album, the like state and `App\Http\FormToken`. BG: set `session.paths`. The others: check forms
    and greetings.
  - add a `pages:work` cron (file + full-count check);
  - run the suites serial and parallel;
  - deploy;
  - verify: a second anonymous GET answers `X-Page-Cache: hit`, a staff publish shows on the next anonymous GET, and
    the login and the admin are not cached.
