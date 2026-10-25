# Cache Registry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every cache registers once in a core `CacheRegistry`. A site lists and clears caches from the CLI (`cache:list`, `cache:clear`) and from Folio (Settings → Cache, with OPcache status). Released as v0.33.0.

**Architecture:** Contracts and two file-based sources live in `preflow/core` (`Preflow\Core\Cache`). `Application` puts one `CacheRegistry` into the container in its constructor, so providers can use it in `register()` and `boot()`. Core and Folio register the caches they always have. The devtools commands boot the app and read the registry. A Folio controller renders the screen.

**Tech Stack:** PHP 8.4, Twig, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-25-cache-registry-design.md`

## Global Constraints

- **Namespace:** `Preflow\Core\Cache`. Keys are stable strings: `data.json`, `blade`, `folio.extensions`, `folio.blocks`. Sites add their own, e.g. `twig`, `routes`, `images`.
- **`CacheStats(int $entries, int $bytes, ?string $note = null)`** and **`CacheCleared(int $entries, int $bytes)`** are `final readonly`.
- **Registering** a duplicate key throws `\LogicException`. `all()` keeps registration order.
- **Clearing never removes** the cache directory itself or a `.gitkeep`. A missing directory or file is 0/0 and no error.
- **`.php` files:** clearing one also calls `opcache_invalidate($file, true)` when that function exists.
- **CLI:** `cache:clear` without arguments clears all registered caches and prints `Note: use "cache:clear --all"; the bare form will require it in a later release.`. It never deletes unregistered files.
- **Folio permission:** `folio.cache`, declared as `('folio.cache', 'Cache', 'Folio')`. This is a ruling against the spec's `cache.manage`, chosen to match `folio.dashboard` and `folio.extensions`.
- **Folio routes:** `GET {prefix}/cache`, `POST {prefix}/cache/clear` (body `key=<key>` or `all=1`) and `POST {prefix}/cache/opcache-reset`. All of them use CSRF (the existing middleware) and all are mapped to `folio.cache` in `FolioPermissionMiddleware`.
- **Sidebar:** Settings → "Cache", always present, icon `ui-settings`, order `950` (after Extensions at 900).
- **Labels** are plain English strings; i18n comes later (spec, "Labels and languages").
- **Constraints:** `preflow/folio` raises its `conflict` to `"preflow/core": "<0.33"`, and `preflow/devtools` gets a new `conflict` of `"preflow/core": "<0.33"`.
- **Gates:**
  - `vendor/bin/phpunit` (baseline v0.32.0: 2953 tests), `composer test:mysql`, and the smeyer.me and smart-stack.works suites.
  - No merge, push or tag without the owner's go-ahead.

## Review Focus

- **Clearing a directory that holds subfolders:** files in subfolders are deleted. Empty subfolders are removed, but the root and `.gitkeep` stay (Task 1).
- **A symlink inside a cache directory pointing outside it:** only the link is removed, never the target's contents, and stats don't follow it (Task 1).
- **A Folio registry file removed through the screen:** the next request rebuilds it and the admin still works (Task 3, end-to-end).
- **An unknown key posted to `{prefix}/cache/clear`:** a 404 text response, and nothing is cleared (Task 3).
- **`cache:clear twig` on a site without a Twig cache:** exit 1 with `Unknown cache: twig` and the list of known keys (Task 2).

---

### Task 1: Core contracts, sources and registry

**Files:**
- Create in `packages/core/src/Cache/`: `CacheSource.php`, `CacheStats.php`, `CacheCleared.php`, `CacheRegistry.php`, `DirectoryCache.php`, `FileCache.php` and `OpcacheStatus.php`.
- Modify `packages/core/src/Application.php`:
  - In the constructor, add `$this->container->instance(CacheRegistry::class, new CacheRegistry())`.
  - In `bootDataLayer`, when a `json` driver is created, register `new DirectoryCache('data.json', 'JSON storage query cache', <storage/cache/data>, pattern: 'json-*.json')`.
  - In `createTemplateEngine`, for `blade`, pass `cachePath: sys_get_temp_dir() . '/preflow_blade_cache'` explicitly. This is the same value as BladeEngine's default, so there is no change in behaviour. When Blade is the engine, register `new DirectoryCache('blade', 'Compiled Blade templates', that path, note: 'shared temp folder')`.
- Modify `packages/core/README.md` and add a "Caches" section:
  - registering a source in a provider;
  - the `twig` example: `new TwigEngine(cachePath: …)` plus `DirectoryCache('twig', 'Compiled Twig templates', …)`;
  - the `routes` example with `FileCache`.
- Test: `packages/core/tests/Cache/CacheRegistryTest.php`, `DirectoryCacheTest.php`, `FileCacheTest.php` and `OpcacheStatusTest.php`, plus `packages/core/tests/CacheRegistryBootTest.php`.

**Interfaces — Produces:**
- `interface CacheSource { key(): string; label(): string; stats(): CacheStats; clear(): CacheCleared; }`
- `CacheRegistry`:
  - `register(CacheSource): void`
  - `all(): list<CacheSource>`
  - `get(string $key): ?CacheSource`
  - `clearAll(): array<string, CacheCleared>`, keyed by key, in order.
- `DirectoryCache::__construct(string $key, string $label, string $dir, ?string $pattern = null, ?string $note = null)`. `$pattern` is an `fnmatch` pattern on file basenames and applies at every depth.
- `FileCache::__construct(string $key, string $label, string $file, ?string $note = null)`
- `OpcacheStatus`:
  - `enabled(): bool` is true when `opcache_get_status(false)` returns an array.
  - `stats(): ?array{memory_used: int, memory_free: int, scripts: int, hit_rate: float}` gives the hit rate as a percentage, rounded to one decimal.
  - `reset(): bool`

- [ ] **Step 1: Failing tests.** Each test uses temp folders.
  - **Registry:**
    - `register` + `get`;
    - `all()` order;
    - a duplicate key throws `LogicException`;
    - `clearAll()` returns `['a' => CacheCleared, 'b' => CacheCleared]` in order.
  - **`DirectoryCache`:**
    - With `a.txt` (3 bytes), `sub/b.txt` (5 bytes) and `.gitkeep`: stats are `entries 2, bytes 8` (`.gitkeep` is not counted).
    - Clear returns `2/8`; the dir and `.gitkeep` remain and `sub/` is gone.
    - A missing dir gives `0/0` from both stats and clear.
    - `pattern: 'json-*.json'` counts and clears only matching files, at every depth.
    - A symlink `link -> /outside/dir` holding `x.txt`: stats ignore it, clear unlinks the link, and `/outside/dir/x.txt` still exists.
    - The note is passed through to `CacheStats::$note`.
  - **`FileCache`:** a present file gives `1/size` and clear deletes it; a missing file gives `0/0`.
  - **`OpcacheStatus`:** `enabled()` matches `is_array(@opcache_get_status(false))`, and `stats()` is null when it is disabled.
  - **`CacheRegistryBootTest`**, with `Application::create([...config])` and a json driver:
    - The registry from the container holds `data.json`.
    - A provider's `register()` can `get(CacheRegistry::class)` and register `site.x`.
    - With `app.engine = blade`, `blade` is registered and `stats()->note === 'shared temp folder'`.
    - With Twig, nothing called `twig` is registered.
- [ ] **Step 2:** `vendor/bin/phpunit packages/core/tests/Cache packages/core/tests/CacheRegistryBootTest.php` fails.
- [ ] **Step 3:** Implement. Walk the directory with `RecursiveDirectoryIterator` without `FOLLOW_SYMLINKS`. Treat symlinks as leaves: `unlink` them on clear and never count them.
- [ ] **Step 4:** The same command passes, and the full `vendor/bin/phpunit` stays green.
- [ ] **Step 5:** Commit `feat(core): cache registry with directory and file sources`.

### Task 2: CLI `cache:list` and `cache:clear`

**Files:**
- Create: `packages/devtools/src/Command/CacheListCommand.php`
- Rewrite: `packages/devtools/src/Command/CacheClearCommand.php`
- Modify:
  - `packages/devtools/src/Console.php` registers `CacheListCommand` next to `CacheClearCommand`.
  - `packages/devtools/composer.json` gets the conflict `"preflow/core": "<0.33"`.
  - `packages/devtools/README.md`, if it lists commands.
- Test: `packages/devtools/tests/Command/CacheCommandsTest.php`

**Interfaces:**
- Consumes: Task 1 `CacheRegistry`, `CacheStats` and `CacheCleared`.
- Produces: both commands take `__construct(private readonly ?\Closure $appFactory = null)`. The app factory works like `MigrateCommand::app()`: it creates the app from `getcwd()` and calls `boot()`.

- [ ] **Step 1: Failing tests.** The app factory returns a booted app whose registry holds two `FileCache`s (`one`, `two`) over temp files.
  - **`cache:list`:**
    - It prints a header row `Key  Label  Entries  Size` and one row per cache in order.
    - Sizes are human-readable: `0 B`, `512 B`, `1.5 KB`, `3.1 MB`, base 1024, one decimal above bytes.
    - An empty registry prints `No caches registered.`.
    - The exit code is 0.
  - **`cache:clear one`:**
    - It deletes only `one` and prints `Cleared one: 1 file(s), <size>.`.
    - The exit code is 0.
  - **`cache:clear twig`:**
    - It prints `Unknown cache: twig (known: one, two)`.
    - The exit code is 1 and nothing is deleted.
  - **`cache:clear --all`:** it clears both and prints one line per cache.
  - **`cache:clear` with no args:**
    - It clears both and prints the deprecation note (Global Constraints) first.
    - An unregistered file `storage/cache/other.txt` survives.
- [ ] **Step 2:** `vendor/bin/phpunit packages/devtools/tests/Command/CacheCommandsTest.php` fails.
- [ ] **Step 3:** Implement. Put the size formatter in a small static helper, `Preflow\Core\Cache\Bytes::human(int): string`, so the Folio screen can reuse it. Add `packages/core/tests/Cache/BytesTest.php` with the values above.
- [ ] **Step 4:** Passes, and `vendor/bin/phpunit packages/devtools packages/core` is green.
- [ ] **Step 5:** Commit `feat(devtools): cache:list and cache:clear use the cache registry`.

### Task 3: Folio registrations and the Cache screen

**Files:**
- Create: `packages/folio/src/Http/CacheController.php` and `packages/folio/templates/admin/cache.twig`.
- Modify:
  - `packages/folio/src/FolioServiceProvider.php`:
    - In `register()`, register `FileCache('folio.extensions', 'Folio extensions', storage/cache/folio-extensions.cache)` and `FileCache('folio.blocks', 'Folio blocks', storage/cache/folio-blocks.cache)`, using the same path expressions as today.
    - Bind `CacheController`.
    - Add the nav entry in `boot()` (Global Constraints).
    - Add `declare('folio.cache', 'Cache', 'Folio')` in `declarePermissions`.
  - `packages/folio/src/Routing/FolioRoutes.php`: the three routes go next to the `/extensions` routes, before the generic `{type}` routes.
  - `packages/folio/src/Http/FolioPermissionMiddleware.php` maps the three actions to `folio.cache`.
  - `packages/folio/composer.json`: the conflict becomes `"preflow/core": "<0.33"`.
  - Route and permission tables in tests that enumerate them, e.g. `packages/rbac/tests/TwoRolesTest.php`, `PermissionsFieldTest.php` and `DelegationTest.php` (grep for `folio.extensions`).
- Test: `packages/folio/tests/Http/CacheScreenTest.php` (`PublishingScaffold` style, like `ExtensionsScreenTest`).

**Interfaces:**
- Consumes:
  - Task 1 `CacheRegistry` and `OpcacheStatus`;
  - Task 2 `Bytes::human()`;
  - `SessionInterface::flash()`, the way `AdminController::flash()` uses it;
  - `CsrfToken` the way `ExtensionsController::index()` uses it.
- Produces:
  - `CacheController::__construct(CacheRegistry $caches, OpcacheStatus $opcache, TemplateEngineInterface $engine, string $prefix)`
  - The actions `index`, `clear` and `opcacheReset`. After a POST, the controller redirects with 302 to `{prefix}/cache`.

- [ ] **Step 1: Failing tests:**
  - `FolioPermissionMiddleware::rule('Preflow\\Folio\\Http\\CacheController@index')` (and the same for `@clear` and `@opcacheReset`) is `folio.cache`.
  - **GET `/folio/cache`:**
    - One card per cache: label, `entries`, `Bytes::human(bytes)` and a form posting `key=<key>` with `_csrf_token`.
    - A "Clear all" form (`all=1`).
    - With OPcache enabled, a card with memory used/free, scripts, hit rate and a "Reset OPcache" form.
    - With OPcache disabled, the text `OPcache is off`. Inject a fake `OpcacheStatus` subclass or an interface to test both. Ruling: make `OpcacheStatus` non-final with no constructor so the test can subclass it.
  - **POST `key=folio.blocks`:**
    - The file is gone and the response is a 302 to `/folio/cache`.
    - The flash is `Folio blocks: 1 file(s), <size> cleared.`.
  - **POST `all=1`:**
    - Every registered cache is cleared.
    - The flash is `Cleared all caches: <n> file(s), <size>.`.
  - **POST `key=nope`:** 404 `Unknown cache`, and nothing is deleted.
  - **The opcache-reset POST:** the flash is `OPcache reset.`, or `OPcache is off.` when it is disabled.
  - **A POST without a CSRF token** gets 403, through the real app.
  - **End-to-end:**
    - After clearing `folio.extensions` on a site with the counter extension, a second `GET /folio` returns 200.
    - The cache file exists again.
  - The sidebar shows "Cache" under Settings.
- [ ] **Step 2:** `vendor/bin/phpunit packages/folio/tests/Http/CacheScreenTest.php` fails.
- [ ] **Step 3:** Implement.
  - The template extends `@folio/admin/_layout.twig` and reuses existing classes (`folio-table`/cards as on the dashboard, `btn`, `folio-notice`).
  - It adds no inline script or style, because of the strict CSP. `CspGuardTest` stays green.
- [ ] **Step 4:** The full `vendor/bin/phpunit` is green.
- [ ] **Step 5:** Commit `feat(folio): Settings → Cache screen with OPcache status`.

### Task 4: Docs and release prep

**Files:**
- Modify:
  - `packages/folio/README.md`: a "Cache screen" paragraph (permission, what it shows, OPcache web-only).
  - `ROADMAP.md`: a v0.33.0 entry and the "Last updated" line.
  - The spec's permission name (`cache.manage` becomes `folio.cache`).
- Create: `docs/superpowers/plans/2026-10-25-release-0.33-checklist.md`, shaped like the 0.30 checklist. Its upgrade notes:
  - `cache:clear` no longer empties `storage/cache` blindly;
  - the bare form is deprecated;
  - sites register their own caches;
  - site tests that assert the Settings sidebar gain "Cache" (smart-stack.works `MembersGroundworkTest::test_sidebar_structure`).

- [ ] Run the full suite, `composer test:mysql`, and the smeyer.me and smart-stack.works suites through their symlinks.
- [ ] If a site test asserts the Settings sidebar, record the needed site change in the checklist and don't edit the site.
- [ ] Record the counts, then commit `chore: prepare 0.33.0`. No merge, push or tag.
