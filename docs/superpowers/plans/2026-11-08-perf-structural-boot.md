# Performance step 2: structural boot — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Boot does only what a request needs. Folio's admin setup happens on demand, config, discovery, catalogs
and public routes come from a per-release compiled manifest, and the DB connects lazily. Targets: robots.txt under
1 ms and languagecorner /home about 3 ms locally.

**Architecture:**
- **Admin setup.** Admin services are registered as lazy singletons. The expensive admin setup (admin routes, nav,
  screens, Customizations) runs idempotently on the first admin-prefixed request and always in CLI.
- **Boot manifest.** `preflow optimize` writes `bootstrap/cache/boot.php`. It is used only with `APP_DEBUG=0` outside
  CLI, and only while the signatures of `.env` and `config/folio/extensions.json` still match.
- **Database.** PDO is created on first use.

**Tech Stack:** PHP 8.4+, PHPUnit 11.5 (root `vendor/bin/phpunit`).

**Spec:** `docs/superpowers/specs/2026-11-08-perf-structural-boot-design.md`. Read its "Calls made after the builder check" first.
**Evidence:** `docs/superpowers/specs/2026-11-07-perf-profiling-report.md`.

## Global Constraints

- No visible behaviour change on public pages, admin or CLI. Every admin screen, public page and command works as before.
- **Never a stale manifest:**
  - `.env` and `config/folio/extensions.json` are checked per request, and a mismatch means a live boot.
  - Data-stored settings are never frozen.
  - The CLI never reads the manifest.
- Public provider interfaces stay. Container additions must be backward compatible. No new runtime dependency.
- Release 0.59.0 with a ROADMAP entry and a before/after table. Then the 5 sites: holz-objekte.org, BG Genius,
  smart-stack.works, smeyer.me, languagecorner.ch. Back up each first, run its suites serial and parallel, and change
  its deploy order to vendors → migrate/extensions → `preflow optimize` → symlink.
- Run test suites one at a time. Never run two suites in parallel.

## Review Focus

1. **One Application serving a public request, then an admin request** (tests, SEO audit renders): the admin request
   gets all admin routes, nav and screens. Test in Task 3.
2. **An editor toggles an extension in Folio** (`config/folio/extensions.json` rewritten) on a site with a manifest: the
   next public request reflects it without `preflow optimize`. Test in Task 4.
3. **`.env` edited on the server after a deploy:** the new values apply on the next request. Test in Task 4.
4. **A cron job or CLI command on a site with a manifest** sees live config. Test in Task 4.
5. **A public request on a MySQL site that runs no query** opens no PDO connection, and one that queries still works,
   including transactions. Test in Task 1.

---

### Task 1: Lazy database connection

**Files:** core/data where PDO is created (find it; MySQL and SQLite drivers, `Application` binding), and tests.

- [ ] Tests:
  - `test_no_connection_is_opened_until_the_first_query`: boot plus a request that runs no query, and the PDO factory
    is not called. Use a counting factory, or check the connection-created flag.
  - `test_first_query_connects_and_later_queries_reuse_it`.
  - `test_transactions_work_on_the_lazy_connection`.
- [ ] Implement: the PDO is created on first use, through a lazy proxy or a closure-resolved singleton; the existing
  SET statements run once when it connects. No change to public signatures of drivers.
- [ ] Commit: `perf(data): connect to the database on first use`

### Task 2: Step-1 leftovers

**Files:**
- `packages/media/src/MediaLibrary.php` (`prefetch`);
- `packages/data/src/Driver/JsonFileDriver.php` (query cache validation);
- `packages/folio/tests/FolioServiceProviderTest.php` and `LazyHtmlSanitizerTest` (temp base path).

- [ ] Tests:
  - `test_prefetch_runs_one_query` (counting PDO).
  - `test_json_cache_validation_does_not_stat_every_record`: count `filemtime`/`stat` calls through a seam, or count
    the files touched. Keep the existing staleness tests green, especially "a record written by another process
    invalidates".
  - After the Folio suite runs, the monorepo has no `storage/` directory.
- [ ] Implement:
  - `prefetch` uses the query path without a COUNT (e.g. `->get()` without pagination, or a raw `IN` through the
    driver).
  - `JsonFileDriver` trusts its generation file and the type directory's mtime. A record added or removed changes the
    directory mtime; a record edited in place goes through the driver, which bumps the generation. Document the edge
    case of a hand-edited record file, which then needs `cache:clear`, in the data README.
  - The two Folio tests use a temp base path.
- [ ] Commit(s): `perf(media): prefetch in one query`, `perf(data): validate the JSON query cache without stat-ing every record`, `test(folio): boot tests use a temp base path`

### Task 3: Folio admin setup on demand

**Files:** `packages/folio/src/FolioServiceProvider.php`, `packages/folio/src/Routing/FolioRoutes.php`, the Kernel/Application hook where a request enters handling, and tests in `packages/folio/tests`.

**Interfaces:**
- Produces: a small, documented core hook, e.g. `Application::onRequest(\Closure $hook)` (or a Kernel middleware
  registered first), called with the raw request before routing. Folio uses it to run `ensureAdmin()` when the path is
  under its prefix. `ensureAdmin()` is idempotent and is also run in CLI boot. The name is the builder's call;
  document it.

- [ ] Tests:
  - `test_a_public_get_builds_no_admin_setup`: after boot plus a public request, none of AdminNav, ScreenRegistry,
    Customizations or the admin route table is built or registered. Check via the container's resolved list, a flag on
    `ensureAdmin`, or the router's route count.
  - `test_an_admin_request_after_a_public_one_gets_all_admin_routes` (Review Focus 1).
  - `test_every_admin_screen_answers_for_an_admin`: reuse the existing admin smoke tests, or add one listing the
    admin routes before and after the change and comparing them.
  - `test_cli_boot_has_the_admin_setup`.
  - `test_component_endpoint_builds_admin_service_only_when_used`.
  - The permission registry still answers `Access::can()` on a public request.
- [ ] Implement as in spec calls 1, 4, 5, 6. Keep the permission registry and the cheap boot checks eager. Measure
  robots.txt and /home on langcorner.dev.smyr.
- [ ] Commit: `perf(folio): admin routes, nav, screens and customizations are set up on the first admin request`

### Task 4: Boot manifest

**Files:** core (`Application` boot, a new `Preflow\Core\Boot\Manifest` + `ManifestBuilder` or similar), devtools (`OptimizeCommand` → `preflow optimize [--check]`, `CacheClearCommand`), the producers of catalogs (components, folio blocks/extensions/models, i18n), `.gitignore` template for sites (`bootstrap/cache/`); tests.

**Interfaces:**
- Produces: `preflow optimize` writes `{basePath}/bootstrap/cache/boot.php` atomically, holding:
  - `version`;
  - `sources`: path → signature, plus the two runtime-checked signatures;
  - `config`: the merged config;
  - `packages`: the discovery data;
  - `catalogs`: components, blocks, extensions, models, as each producer needs;
  - `routes`: filled in Task 5.

  `preflow optimize --check` prints drift and exits non-zero when sources changed. Boot reads the manifest only when
  `APP_DEBUG=0`, the SAPI is not CLI, and the runtime-checked signatures match. Each catalog producer gets a way to
  take its data from the manifest instead of scanning. The form (an interface or an optional constructor argument) is
  the builder's call.

- [ ] Tests:
  - `test_optimize_writes_the_manifest_and_boot_uses_it`: with the source files unreadable or renamed after
    optimize, a non-CLI production boot still works. Simulate the SAPI through a seam.
  - `test_debug_mode_ignores_the_manifest`.
  - `test_cli_ignores_the_manifest`.
  - `test_a_changed_env_file_bypasses_the_manifest` (Review Focus 3).
  - `test_a_changed_extensions_json_bypasses_the_manifest` (Review Focus 2). Also: the extensions writer rebuilds or
    removes the manifest when it writes.
  - `test_cache_clear_removes_the_manifest`.
  - `test_optimize_check_reports_drift`.
  - `test_output_is_identical_with_and_without_manifest` for a fixture app: same HTML, same config values.
- [ ] Implement per spec goal 2 and calls 2, 3, 7, 8. Measure.
- [ ] Commit(s): `perf(core): compiled boot manifest (preflow optimize)`, plus producer commits as needed.

### Task 5: Route cache

**Files:** core routing (`Router`, route compiler), the Folio frontend routes, the manifest builder.

- [ ] Tests:
  - `test_public_routes_come_compiled_from_the_manifest`: no pattern compiling (`preg_quote` via a seam, or a compile
    counter) on a manifest boot.
  - `test_admin_routes_compile_on_the_first_admin_request`.
  - `test_routing_matches_identically_with_and_without_cache`: a table of paths against the expected handlers,
    including the locale prefix, redirects and 404.
- [ ] Implement per spec goal 3 and call 9.
- [ ] Commit: `perf(routing): public routes are compiled once into the boot manifest`

### Task 6: Release 0.59.0 and the sites (controller)

- [ ] Measure before (0.58.1) and after on languagecorner (/home, 404, robots.txt, /classes) and holz (home, list,
  /album, robots.txt).
- [ ] Write the ROADMAP entry and run the full monorepo suite. Tag, push, check Packagist.
- [ ] For each site:
  - back up;
  - bump the pin to `^0.59` and run `composer update 'preflow/*'`;
  - add `bootstrap/cache/` to `.gitignore`;
  - in `deploy.php`, add `preflow optimize` after migrate/extensions and before the symlink;
  - run the suites serial and parallel;
  - commit, push and deploy;
  - check that home and the Folio login answer 200 and that `preflow optimize --check` is clean on the server.
- [ ] holz-objekte: batch `Catalog::object` for /album (site agent).
