# Performance step 1: quick wins — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the per-request waste the profiling found (Twig compiling every template, re-minifying, re-reading
installed.json, an eager sanitizer, the 404 writes, the media N+1, repeated parsing) and fix the emitter. Ship as
0.58.0, with the same output and no stale caches.

**Architecture:** Each fix is local to the package that owns the code, with content-, mtime- or release-keyed caches
under the app's cache path. No public interface changes.

**Tech Stack:** PHP 8.4+, PHPUnit 11.5 (root `vendor/bin/phpunit`), Twig 3, matthiasmullie/minify.

**Spec:** `docs/superpowers/specs/2026-11-07-perf-quick-wins-design.md`
**Evidence:** `docs/superpowers/specs/2026-11-07-perf-profiling-report.md`

## Global Constraints

- Every page renders the same HTML before and after; only nonce and token values may differ.
- No stale template, asset or manifest after an edit or a deploy. Caches are keyed by content hash, mtime+size, or live under the per-release `storage/cache`.
- No public interface change (no BC break for extensions or sites). No new runtime dependency.
- `preflow cache:clear` empties every new cache.
- Release 0.58.0 with a ROADMAP entry and before/after timings. Then upgrade and deploy holz-objekte.org, BG Genius,
  smart-stack.works, smeyer.me and languagecorner.ch, each after a backup, with its suites green (serial and parallel).

## Review Focus

1. **A template edited in development shows up on the next request** (`auto_reload`), and so does a block template
   inside a component. Test in Task 1.
2. **Two different CSS inputs that minify to the same output, or a hash collision across sites sharing a cache
   directory.** The key includes the input hash and the minifier version. Test in Task 2.
3. **`composer update` changes installed.json, and the next request sees the new packages and translations.** Test in
   Task 3.
4. **A scanner hitting 5,000 random 404 paths:** at most 1,000 entries are recorded, and no write happens for an
   already-known path within 60 seconds. Test in Task 5.
5. **HEAD, 204, 304 and a streamed download each get correct headers on HTTP/1.0 and HTTP/1.1.** Test in Task 6.

---

### Task 1: Twig template cache
**Files:** `packages/core/src/Application.php` (~635–700, `createTemplateEngine`, and the Blade branch), `packages/twig/src/TwigEngine.php` if needed, `packages/devtools/src/Command/CacheClearCommand.php`; tests in `packages/core/tests` / `packages/twig/tests`.
- [ ] Tests:
  - `test_twig_compiles_into_the_cache_directory`: after one render, `{cachePath}/twig` holds compiled templates; a second render compiles nothing (assert via a counting loader or the cache directory's contents).
  - `test_an_edited_template_recompiles`: change the source file (touch with a newer mtime) and the next render shows the edit.
  - `test_output_is_identical_with_and_without_cache`: render a fixture page with blocks and components both ways and compare after normalising `nonce="…"` and token values.
  - `test_cache_clear_empties_the_twig_cache`.
- [ ] Implement: pass `{app cache path}/twig` to `TwigEngine` (`cachePath`), keep `auto_reload` on, and give Blade the same rule if it lacks a compiled-view cache. Register the directory with `cache:clear`.
- [ ] Commit: `perf(core): Twig compiles each template once, into storage/cache/twig`

### Task 2: Minify cache
**Files:** `packages/view/src/AssetCollector.php` (`minifyCss`/`minifyJs`, ~206–215), and its tests.
- [ ] Tests:
  - `test_minified_css_is_reused_for_the_same_input`: a spy, or a minifier call counter, shows one minify for two renders.
  - `test_cached_output_equals_fresh_output`.
  - `test_different_input_gets_its_own_entry`.
  - `test_without_a_cache_path_it_still_minifies`.
- [ ] Implement:
  - The key is xxh3 of `minifier version + kind + input`.
  - Storage is a PHP file returning the string, under `{cache}/assets`, written atomically (temp file + rename).
  - Without a cache path, today's behaviour stays.
  - `cache:clear` covers it.
- [ ] Commit: `perf(view): minified CSS and JS are cached by content`

### Task 3: installed.json read once per deploy
**Files:** `packages/i18n/src/TranslationRegistry.php` (`fromInstalled`), `packages/folio/src/Extension/ExtensionRegistry.php` (`builtIn` ~387, `packageClasses` ~411), and their call sites in the providers; tests in both packages.
- [ ] Tests:
  - `test_installed_json_is_decoded_once_and_then_served_from_cache`: count reads, or use a fixture whose cache file proves reuse.
  - `test_a_changed_installed_json_is_read_again`: change mtime and size, and the new package's translations and extension classes show.
  - `test_builtin_runs_after_the_cache_check`.
- [ ] Implement:
  - Each consumer gets its parsed result from a small shared helper, e.g. `Preflow\Core\Composer\InstalledPackages` in core. It caches the decoded subset each consumer needs, keyed by path + mtime + size, as an OPcache-able PHP file under `{cache}/composer`. The helper's exact name is the builder's call.
  - Each consumer keeps its public signature.
- [ ] Commit: `perf: read composer's installed.json at most once per change`

### Task 4: Lazy rich-text sanitizer
**Files:** `packages/folio/src/FolioServiceProvider.php` (~545, `registerFieldKinds`), the rich-text field type and its sanitizer user (`SafeRichText`); tests in `packages/folio/tests`.
- [ ] Tests:
  - `test_booting_folio_builds_no_sanitizer`: after boot plus a request that renders no rich text, the `HtmlSanitizerConfig`/`HtmlSanitizer` service is not resolved. Check via the container's resolved list or a constructor counter.
  - `test_rich_text_is_still_sanitized`: an existing sanitizer test stays green.
- [ ] Implement: build the sanitizer config lazily, through a closure-backed singleton, and resolve it only inside sanitize calls. If `registerFieldKinds` instantiates field types only to read their kind, read the kind from the registration data instead. Keep interfaces unchanged.
- [ ] Commit: `perf(folio): build the HTML sanitizer only when rich text is sanitized`

### Task 5: 404 counter throttle and cap
**Files:** `packages/folio/src/Redirects/NotFoundEntries.php` (`write`) and its tests.
- [ ] Tests:
  - `test_a_known_path_is_written_at_most_once_a_minute`: two hits within 60 s give one write (inject a clock); a hit after 60 s writes again.
  - `test_new_paths_stop_at_the_cap`: with 1,000 entries, a new path is not added, and known paths still update (per the minute rule).
  - `test_a_list_over_the_cap_is_left_as_it_is`.
- [ ] Implement:
  - The last-seen time comes from the loaded entry.
  - Hits within the window are dropped (ruling in the spec).
  - The cap and the 60 s window are class constants.
  - Document the meaning in the Folio redirects screen help, or the README, wherever counts are explained.
- [ ] Commit: `perf(folio): 404 hits are written at most once a minute per path, capped at 1,000 paths`

### Task 6: Emitter — Content-Length and protocol version
**Files:** `packages/core/src/Http/Emitter.php` and its tests.
- [ ] Tests:
  - `test_known_size_body_gets_content_length`.
  - `test_http10_request_gets_http10_status_line_and_no_chunked`.
  - `test_head_keeps_the_get_length_and_sends_no_body`.
  - `test_204_and_304_send_no_length_and_no_body`.
  - `test_streamed_body_gets_no_content_length`.
- [ ] Implement: use the response's (or request's) protocol version for the status line; the response factory or middleware sets the protocol version from the request. Set Content-Length when `getBody()->getSize()` is known and no `Transfer-Encoding` is set.
- [ ] Verify by hand: `ab -n 50 -c 5` against a local Preflow page now completes.
- [ ] Commit: `fix(core): Content-Length and the request's HTTP version in responses`

### Task 7: Media lookups without N+1
**Files:** `packages/media/src/MediaLibrary.php` (`record` ~591, and the callers used while rendering images: ImageTag / the media Twig functions); tests in `packages/media/tests`.
- [ ] Tests:
  - `test_rendering_n_images_runs_one_asset_query`: count queries with a counting PDO or the data layer's query log; render 25 images of one list and expect ≤ 2 asset queries.
  - `test_unknown_ids_still_render_nothing`.
  - `test_memo_is_per_request` (no stale asset after an update in the same process: clear on save).
- [ ] Implement: a per-request memo, plus batch loading. Either the record lookup collects ids and fetches with one `WHERE uuid IN (…)` when a list renders, or a `prefetch(array $ids)` is called by the list components. Builder's call; prefer the one that needs no change in site templates.
- [ ] Commit: `perf(media): asset records for a render are fetched in one query`

### Task 8: Parse model JSON and folio.php once
**Files:** `packages/data/src/TypeRegistry.php`, `packages/folio/src/Content/TypeCatalog.php`, `packages/folio/src/Extension/ModelExtension.php`, `packages/folio/src/FolioServiceProvider.php` (~1872, the config read); tests.
- [ ] Tests:
  - `test_each_model_file_is_decoded_once_per_boot`: a counting reader or file-read spy.
  - `test_folio_config_is_read_once`.
- [ ] Implement: a shared parsed-model source per app (memoised by path + mtime) used by all three; memoise the provider's config read in a property.
- [ ] Commit: `perf: model files and folio config are parsed once per request`

### Task 9: Test runs leave no cache files; cache:clear removes orphans
**Files:** `packages/testing/src/…` (the helpers sites use for per-test data paths), `packages/data/src/Driver/JsonFileDriver.php` (cache dir), `packages/devtools/src/Command/CacheClearCommand.php`; tests.
- [ ] Tests:
  - `test_a_test_data_path_keeps_its_json_cache_under_its_temp_root` (testing helper).
  - `test_cache_clear_removes_json_cache_files_of_vanished_data_paths`: create files for a path key whose data path no longer exists; after `cache:clear` they are gone, and current ones are gone too, as today.
- [ ] Implement: when a test scaffold sets `DATA_PATH`, it also sets the data cache directory under the same temp root. Document this in `packages/testing/README.md` "Fast suites". `cache:clear` already clears; make sure it globs every path key.
- [ ] Commit: `fix(testing): per-test JSON caches live under the test's temp root`

### Task 10: Measure, release 0.58.0, sites (controller)
- [ ] Measure the reference pages with `hey -c 1 -n 300` on Apache: languagecorner /home, /no-such-page, /robots.txt; holz home, a list page, robots.txt. Use the same pages as the report, before (0.57) and after.
- [ ] Write the ROADMAP 0.58.0 entry with the table. Run the full monorepo suite. Tag, push, and check Packagist.
- [ ] For each of the 5 sites:
  - back up;
  - bump the pin to `^0.58` and run `composer update 'preflow/*'`;
  - adapt the site scaffolds per Task 9 (data cache dir under the temp root) if the site sets DATA_PATH itself;
  - run the suites serial and parallel;
  - commit, push and deploy;
  - check that home and the Folio login answer 200.
- [ ] holz-objekte: cache or index `App\Gallery\Catalog::names` (8.4 ms on its home page), with a test. Then measure again.
- [ ] Run `preflow cache:clear` once in each local site to remove the stale test cache files. Confirm that languagecorner's 31,550 files are gone.
