# Performance step 2: a structurally lighter boot (Preflow 0.59)

The owner asked for this on 2026-11-07/08: "as fast as possible", "push through all step by step", and for step 2
"straight forward". This is step 2 of 3. Step 1 (0.58.1) removed the per-request waste. Step 3 is the page cache with
rebuild on publish.

Evidence:
- `docs/superpowers/specs/2026-11-07-perf-profiling-report.md`, sections "robots.txt (the floor)" and "Laravel gap";
- ROADMAP 0.58.

## Why

After step 1, languagecorner timings locally (Apache, OPcache, one request at a time) are:

| Request | Time |
|---|---|
| robots.txt | 2.2 ms (pure boot) |
| /home | 5.0 ms |
| 404 | 3.9 ms |

An optimised Laravel page takes ~2 ms. The remaining cost is boot work every request repeats although its result only
changes on deploy:
- Folio's admin-only services (34 singletons resolved at boot), its admin routes (1,373 `preg_quote` calls compiling
  patterns) and Customizations reflection (384 `getAttributes` calls) are built on every public GET.
- Config files are read on every request.
- Component, block, extension and model catalogs are scanned on every request.
- The PDO connection is opened eagerly on MySQL sites.

`Application.php` (~2,000 lines) builds everything in `boot()`.

## Goals

The targets are measured with the step 1 method:
- robots.txt under 1 ms locally;
- languagecorner /home about 3 ms.

Goals:

1. **Admin only when needed.**
   - On a request outside the Folio admin prefix, and outside the paths that need admin services (`/--component`
     actions used by the admin, Folio's own public endpoints), Folio does not build its admin services, admin routes,
     admin navigation, screen registry, customizations or permission maps.
   - Folio registers them lazily: container singletons that build on first use, and admin routes registered only when
     the request path is under the prefix (or in CLI).
   - Public pages still get everything they use: publishing, frontend resolver, blocks, media, SEO/GEO.
   - Behaviour is unchanged. Every admin screen, every public page and every CLI command works as before. Tests prove
     that a public GET builds none of the admin set.
2. **A compiled boot manifest.**
   - `preflow optimize` writes an OPcache-friendly PHP array (location: call 8 below), containing:
     - the merged config, i.e. every `config/*.php` result;
     - the package discovery data (installed packages, translations, extensions);
     - the component, block, extension and model catalogs;
     - the compiled route table (goal 3).
   - With `APP_DEBUG=0` and the manifest present, boot reads it instead of scanning and reading those sources.
   - Without a manifest, or with debug on, boot works exactly as today, so development never sees a stale manifest.
   - `cache:clear` removes it.
   - Every site's deploy runs `preflow optimize` after `composer install` and before the symlink switch. BG Genius,
     whose storage is shared, clears and rebuilds it per deploy.
3. **Route cache.** The compiled route patterns (app, Folio frontend and, under the admin prefix, admin routes) are
   part of the manifest, so no pattern compiling happens per request.
4. **Lazy database connection.** PDO connects on first query, not at boot. A public page that doesn't query (robots.txt,
   a page served from data files) opens no connection.
5. **Leftovers from step 1:**
   - `MediaLibrary::prefetch()` uses one query, not COUNT + SELECT.
   - `JsonFileDriver` validates its query cache from the generation file plus directory mtime, not a stat of every
     record file. Same correctness, fewer syscalls.
   - The two Folio tests that boot with the repo root as base path get a temp root.
   - holz-objekte `/album`: `Catalog::object` per album item is batched. This is site work.

## Non-goals
- **The page cache** (step 3).
- **Rewriting `Application.php` wholesale.** Change what the goals need; splitting the file further is allowed where it
  makes the lazy parts clearer, but it is not a goal.
- **OPcache preload.** It depends on the host.

## Anti-goals
- **A stale manifest.**
  - Anything that can change without a deploy is never frozen into the manifest:
    - runtime-written files such as `config/folio/extensions.json` (written by Folio's extensions screen and
      `folio:extensions:apply`);
    - the `.env`;
    - Folio settings stored as data.
  - Such values stay read per request, or the manifest is invalidated when they are written. The builder decides
    which, per source, and documents it.
  - Sources that are frozen are named in the manifest with their mtime, and `preflow optimize --check` reports drift.
- **Any visible behaviour change** on public pages, the admin or the CLI.
- **A deploy that needs a manual step.**

## Constraints
- PHP 8.4+, current packages. No new runtime dependency.
- Public interfaces of service providers stay. Lazy registration uses the container's existing closure singletons, or
  a small addition to the container that doesn't break callers.
- Release as 0.59.0 with a ROADMAP entry and a before/after table.
- Then upgrade and deploy the 5 sites (backup first, suites green serial and parallel), including the deploy-script
  change for `preflow optimize`.

## Calls made after the builder check (mine; the owner said "straight forward")

1. **Admin setup is triggered by the request, not by boot.**
   - One booted Application can serve public and admin requests; tests and the SEO audit's internal renders do.
   - Folio registers its admin services as lazy container singletons. That is cheap: closures, not built.
   - The expensive admin setup runs on demand, idempotently, the first time a request under the admin prefix is
     handled, and always in CLI. It covers admin route registration and compiling, the nav, the screen registry and
     Customizations.
   - The check runs on the raw request path before locale handling. The admin prefix is never localised.
2. **Manifest validity is checked on every request, cheaply.** The manifest records the signature (path + mtime +
   size) of every source it froze:
   - `config/*.php`;
   - `.env`;
   - `config/folio/extensions.json`;
   - `vendor/composer/installed.json`;
   - the model, component and block directories' file lists.

   Boot stats only the few runtime-mutable sources (`.env`, `config/folio/extensions.json`) per request. If either
   changed, the manifest is ignored for that request (live boot, as today), and the next `preflow optimize` (or the
   extensions writer) rebuilds it. A live change therefore takes effect immediately, with no stale state and no
   cross-worker invalidation. Files that only change on deploy (config, installed.json, code) are trusted until the next
   `preflow optimize`; deploys always run it. Data-stored settings never enter the manifest.
3. **Config files evaluate env values when they load, so the merged config freezes them.** That is safe because of
   call 2: an `.env` change invalidates the manifest. Deploy order: `deploy:env` (if any) → vendors → migrate/extensions
   apply → `preflow optimize` → symlink.
4. **The permission registry stays eager.** Public pages call `Access::can()` (members, FrontendGate, private files).
   Only admin nav, screens, Customizations and admin routes become lazy. The cheap boot checks that throw on
   misconfiguration (`requireRbacForMembers`, codes fields) stay at boot.
5. **`/--component` requests** boot lean like public pages. A component that needs an admin service gets it from the
   lazy singleton, built on use. No token flag, no change to the components package.
6. **Site or extension providers that touch admin services in their own `boot()`** simply cause those to be built:
   correct, only slower. No container hook is needed; tests list which admin services a plain public GET must not
   build.
7. **The CLI never reads the manifest.** It always boots live, so cron jobs and `folio:extensions:*` see current files.
8. **The manifest is per release:** `bootstrap/cache/boot.php` under the app's base path, not `storage/cache`.
   - BG Genius shares `storage`, and a live site must never read another release's manifest.
   - Written atomically (tmp + rename), git-ignored, and removed by `cache:clear`.
9. **Route cache scope:** app and Folio frontend routes go in the manifest. Admin routes are compiled on the first
   admin request in a process; admin traffic is rare, so that is the lazy part from call 1.
10. **The five sites** are holz-objekte.org, BG Genius, smart-stack.works, smeyer.me and languagecorner.ch. All are on
    Preflow; languagecorner is the measurement reference.

## Left to the builder
- The manifest format and which catalogs go in it, as long as nothing runtime-mutable is frozen.
- How "admin request" is detected, given the configurable prefix and component action routes.
- Whether the lazy admin set is a separate provider or lazy closures inside the existing one.

## Verification
- **Tests:**
  - A public GET resolves none of a named list of admin services and registers no admin routes.
  - Every admin screen still answers 200 for an admin.
  - With a manifest, config, catalogs and routes come from it.
  - Writing `config/folio/extensions.json` (or whatever is kept dynamic) takes effect without rebuilding.
  - `cache:clear` removes the manifest.
  - Debug mode ignores it.
  - No PDO connection is made for robots.txt on a MySQL site.
  - Prefetch makes one query.
- **Full monorepo suite** and every site suite, serial and parallel.
- **Measured before/after** on languagecorner (/home, 404, robots.txt, /classes) and holz (home, a list, /album,
  robots.txt).
