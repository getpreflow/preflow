# Performance step 1: quick wins (Preflow 0.58)

Owner, 2026-11-07: "I really want to be as fast as possible … better do it now than later while accumulating maybe
more performance drains", then "let's push through all, step by step". This is step 1 of three:
1. this design: quick wins;
2. structural boot work (lazy providers, compiled manifest, route cache);
3. a page cache with rebuild on publish.

Evidence: the profiling report from 2026-11-07, `docs/superpowers/specs/2026-11-07-perf-profiling-report.md`.
It used excimer and xdebug, with A/B runs on languagecorner and holz-objekte. Its numbers are quoted below.

## Why

| Page | Today | Of which Twig compiling templates |
|---|---|---|
| A Preflow content page | ~15 ms locally, ~55 ms live | half (7.2 ms on languagecorner /home, ~19 ms on a holz list page) |

Every page compiles every Twig template on every request: `Application::createTemplateEngine()` never passes a cache
path, so `TwigEngine` runs with `'cache' => false`. A few smaller costs repeat on every request too, and are cheap to
remove.

## Goals (each measured before and after)

1. **Twig template cache on.**
   - `Application` passes a cache directory to `TwigEngine`: `storage/cache/twig` under the app's cache path, wherever
     the app keeps its other caches.
   - `auto_reload` stays on, so edited templates recompile in development.
   - `preflow cache:clear` empties it.
   - Check that Blade, the other engine, caches its compiled views the same way; it uses the same rule.
   - Deploys keep `storage/cache` per release (as today), so every release starts with a fresh template cache.
   - Measured on the reference pages: languagecorner /home 15.2 → ~7.7 ms; holz list 38.6 → ~18.7 ms.
2. **Minified CSS/JS cached by content.** `AssetCollector` minifies again on every request today. Minified output is
   kept under a hash of the input, in a file cache under the cache path; the same input is never minified twice. Saves
   ~1.1 ms on /home and ~2.5 ms on the holz list. Output is identical (test).
3. **Read `vendor/composer/installed.json` at most once per deploy, not 2–3× per request.**
   - Today 227 KB of JSON is decoded per request by `TranslationRegistry::fromInstalled`,
     `ExtensionRegistry::builtIn` and `ExtensionRegistry::packageClasses`.
   - The parsed results come from a cache keyed by the file's mtime and size, stored as a PHP file so OPcache serves
     it. `builtIn` runs before its cache check today; it moves behind it.
   - Saves ~1.0 ms on every request, robots.txt included.
4. **No eager rich-text sanitizer at boot.**
   - `FolioServiceProvider` (~line 545) builds an `HtmlSanitizerConfig` while `registerFieldKinds` resolves field
     types, on every request.
   - Build it lazily, only when rich text is actually sanitized. Field kinds come from static metadata, not from
     instantiated field types where that is what forces the build.
   - Saves ~0.5 ms on every request.
5. **The 404 counter doesn't write on every hit.**
   - Today `NotFoundEntries::write` does a JSON read-modify-write per 404 and bumps the data generation, which
     invalidates that cache.
   - A known path is written at most once per 60 seconds, so counts can lag by up to a minute. That is fine; the list
     is for editors.
   - New paths stop being recorded after a cap of 1,000 entries, so scanners can't grow it without bound. The cap and
     interval are constants or config, at the builder's call.
   - Saves ~0.3 ms per 404, and scanners no longer write to disk.
6. **Correct HTTP responses.**
   - The emitter sends `Content-Length` when the body size is known, which covers every normal response.
   - It answers with the request's protocol version: an HTTP/1.0 request gets HTTP/1.0, with no chunked encoding.
   - Test with an HTTP/1.0 request. After the fix, `ab` works against Preflow.
7. **No N+1 for media.** `MediaLibrary::record` is called once per image (25 single-row `folio_assets` queries on a
   holz list page). Records needed for one render are fetched in one query, by preloading the ids a list renders or by
   a per-request batch/memo in `MediaLibrary`, at the builder's call. Saves ~3.5 ms on the holz list.
8. **Cheap repeated work.**
   - Each model JSON is parsed once per request, not 4× (TypeRegistry ×2, TypeCatalog, ModelExtension); share the
     parsed array.
   - `config/folio.php` is read once per request, not 21×.
   - Together ~0.3 ms, more on holz.
9. **Test runs don't leave cache files behind.**
   - Since 0.56.1 the JSON cache is per data path. Each test's own data path leaves `json-*` files in the site's
     shared `storage/cache/data`; languagecorner had 31,550 files (220 MB).
   - Tests get their cache directory under their own temp root, so the files go away with it (preflow/testing helpers
     and the site scaffolds).
   - `preflow cache:clear` also removes cache files whose data path no longer exists.
   - The local sites' stale files are cleared once.
10. **holz-objekte's own `App\Gallery\Catalog::names`** (8.4 ms on its home page): cached or indexed in the site,
    whichever its data needs. This is site work, done with the 0.58 upgrade.

## Non-goals
- Lazy providers, a compiled boot manifest and a route cache: step 2.
- A page cache: step 3.
- OPcache preload: hosting dependent and only ~0.6 ms.
- An optimised autoloader is already used in deploys (`--optimize-autoloader`), so nothing to change there.

## Anti-goals
- **A stale template or a stale asset after a deploy or an edit.** Correctness first. Every cache in this step is keyed
  by content, mtime or release.
- **Different output.** Every page renders byte-identical HTML before and after, apart from per-request values (nonce,
  tokens). Test this.
- **A cache that needs a manual step on the server.**

## Constraints
- PHP 8.4+, current packages. No new runtime dependency.
- Release as 0.58.0, with a ROADMAP entry. Then upgrade and deploy all 5 sites, each after a backup and with its suites
  green.
- Re-measure the reference pages with the same method as the report (scratch router or Apache plus `hey`), and record
  before/after in the ROADMAP entry.

## Calls made after the builder check (mine)

1. **404 hits within the 60-second window are dropped, not accumulated.** A path's count then means "seen, at most
   once a minute". That is enough for an editor deciding on a redirect, and needs no second store.
   - "Last written" comes from the entry's own last-seen time in the already-loaded (cached) list, so no extra read.
   - A list already over the cap is left as it is; only new paths are refused.
2. **Goal 4 stays internal.** No change to the public field-type interfaces (no BC break for extensions). The
   sanitizer config becomes a lazy closure or lazily built service inside the existing types or the provider.
3. **The five sites are holz-objekte.org, BG Genius, smart-stack.works, smeyer.me and languagecorner.ch.**
   languagecorner moved to Preflow on 2026-11-07 and is the profiling reference.
4. **A cold first request after a deploy is fine.** Each template compiles once per release, about 20 ms once, so
   there is no warm-up step. `storage/cache` is writable on every deploy (it already holds the data and block caches).
5. **Emitter edge cases:**
   - Streamed or unknown-length bodies (downloads, server-sent events) get no Content-Length, and on HTTP/1.0 the
     connection closes.
   - HEAD keeps the GET length.
   - 204 and 304 send no body and no Content-Length.
6. **Where the "identical output" check runs:** in the test suites. Reference pages are rendered with the Twig and
   minify caches off and then on. Nonce and token attributes are normalised before comparing. No snapshots of the real
   sites.
7. **Stale cache files:**
   - `preflow cache:clear` removes orphaned `json-*` files by default; they are a cache.
   - The local sites are cleaned once by running it.
   - The live servers have one data path each, so there is nothing to clean there.

## Left to the builder
- Exact cache file layout and names.
- How the media batch is done.
- Where shared model parsing lives.

## Verification
- **Unit and integration tests per goal:**
  - Twig cache used and invalidated by an edit (`auto_reload`).
  - Minify cache hit returns identical output.
  - Manifest cache invalidated when installed.json changes.
  - No sanitizer built on a request without rich text.
  - 404 write throttled and capped.
  - Content-Length and HTTP/1.0 handled.
  - One media query for N images.
  - Test temp roots hold their cache files.
- Full monorepo suite, and every site suite serial and parallel.
- Measured before/after table for languagecorner /home, /no-such-page, /robots.txt and holz list, home and robots.txt.
