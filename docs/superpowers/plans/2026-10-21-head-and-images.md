# HEAD Requests and Responsive Images Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:**
- `HEAD` requests are answered like `GET`, without a body.
- Folio images give browsers sizes close to what they need: finer width steps, with the extra widths generated the first time they are requested.
- The page's most important image gets priority loading (high fetch priority, eager loading, a preload link).

**Architecture:**
- **HEAD:** the route matcher falls back from `HEAD` to the matching `GET` route, and `Application::handle` empties the body of `HEAD` responses.
- **Widths:** each image preset keeps its `widths` (made at upload, as today) and may add `step`: extra widths every *step* pixels between its smallest and largest width. Those extra widths are made on demand.
- **On-demand variants:** a missing `/media/…-{w}.{fmt}` file reaches the front controller, as any missing file already does. A Folio route checks it against the allow-list, generates it once under a lock, writes it next to the original and returns it. From then on the web server serves it as a static file.
- **Allow-list:**
  - the asset must exist;
  - the file name must match the asset's path;
  - the width must be one the presets define, at most the original width;
  - the format must be one the image backend supports.

  That is a finite set, so the URLs need no signing.
- **Priority:** a preset (or a single call) can set `priority: true`. The `<img>` then gets `fetchpriority="high"` and `loading="eager"`, and a `<link rel="preload" as="image" imagesrcset imagesizes type>` goes into the head, once per asset and preset.

**Tech Stack:** PHP 8.4, GD/Imagick backends (existing), PHPUnit 11.

**Spec:** none. This came from a user request in this session: port the useful parts of Crelish's Glide helper, and fix `HEAD`. The Crelish findings are summarized in the session; this plan is the design. Rulings are provisional against that request.

## Global Constraints

- **Static delivery:** existing variants stay static files, with no PHP on delivery. Only a variant's first request goes through PHP.
- **Asset URLs:** `{assets.url}/Y/m/{id}-{w}.{avif|webp}` keeps its shape, so already-generated variants keep working.
- **Upscaling:** never. A width above the original is not allowed and never appears in a `srcset`.
- **Abuse limits:** nothing outside the allow-list is ever generated, and a request outside it is a 404 that writes nothing.
- **Concurrency:** generation takes a lock per variant file, so concurrent first requests encode once.
- **First-request headers:** the response is the image, with `Cache-Control: public, max-age=31536000` (matching the static lifetime on smeyer.me) and the right `Content-Type`.
- **Unchanged:** existing `folio_image` calls keep working, and a preset without `step` or `priority` behaves exactly as today.

## Review Focus

1. **Missing-file path:** a request for a missing variant whose asset, width or format is not allowed must be a 404 with nothing written. That covers path tricks, widths above the original, an unknown asset id, a GIF or an SVG.
2. **Concurrency:** two concurrent first requests for the same variant must encode it once, and neither may be served a half-written file.
3. **Route order:** the media route must not shadow the Folio frontend catch-all for other paths, and a missing non-variant file under `/media` stays a 404.
4. **HEAD:**
   - `HEAD` must never run a non-GET handler;
   - its body is empty, its status and headers match `GET`;
   - CSRF still treats it as safe.
5. **Preload markup:** the link uses the format the first `<source>` uses, and the asset's `sizes`. It is not repeated when the same image appears twice. When the template already rendered `head()` before the image, the tag is not lost silently: it goes into the injected head tags if possible, and the README says what to do otherwise.

---

### Task 1: HEAD requests

**Files:**
- `packages/routing/src/RouteMatcher.php`
- `packages/core/src/Application.php` (`handle`)
- Tests:
  - `packages/routing/tests/RouteMatcherTest.php`
  - `packages/core/tests/HeadRequestTest.php` (new, full app)

- [ ] **Step 1:** Tests:
  - `RouteMatcherTest::test_head_falls_back_to_get` (static, dynamic and catch-all entries);
  - `test_head_does_not_match_post_only_routes`;
  - `HeadRequestTest::test_head_returns_get_status_and_headers_without_body`: an app with a GET page; HEAD gives 200, the same `Content-Type`, and an empty body;
  - `test_head_on_unknown_path_is_404`.
- [ ] **Step 2:** Run. Expected: FAIL (404 for HEAD).
- [ ] **Step 3:** Implement:
  - in `match()`, if the method is `HEAD` and no entry matches, match again as `GET`;
  - in `Application::handle`, when the request method is `HEAD`, replace the body with an empty stream and keep the headers.
- [ ] **Step 4:** Run the routing and core suites. Expected: PASS.
- [ ] **Step 5:** Commit `fix(core): HEAD requests are answered like GET without a body`.

### Task 2: Priority images

**Files:**
- `packages/view/src/AssetCollector.php` (`addHeadTag(string $tag, ?string $key = null)`, deduplicated by key)
- `packages/folio/src/Assets/ImageTag.php`
- `packages/folio/README.md`
- Tests: `packages/view/tests/AssetCollectorTest.php`, `packages/folio/tests/Assets/ImageTagTest.php`

**Interfaces:**
- **Option:** `priority` (bool), from the preset or the call; the call wins.
- **When true:**
  - the `<img>` gets `loading="eager"` and `fetchpriority="high"`;
  - a preload link is added with key `folio-preload:{assetId}:{preset}`:
    ```
    <link rel="preload" as="image" type="image/avif|image/webp" imagesrcset="…" imagesizes="…">
    ```
    Its format is the first `<source>`'s format. With no variants it falls back to `href=original`.
- **When false:** unchanged.

- [ ] **Step 1:** Tests:
  - `AssetCollectorTest::test_keyed_head_tags_are_added_once`;
  - `ImageTagTest::test_priority_sets_eager_fetchpriority_and_one_preload_link`: rendered twice, the link appears once, with the avif `type` and the same srcset as the avif source;
  - `test_without_priority_nothing_changes`;
  - `test_call_option_overrides_preset_priority`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** PASS.
- [ ] **Step 5:** Commit `feat(folio): priority images (fetchpriority, eager, preload)`.

### Task 3: Width model, with eager and lazy widths

**Files:**
- Create: `packages/folio/src/Assets/ImageWidths.php`
- Modify:
  - `FolioServiceProvider` (wiring, replacing `variantWidths()`)
  - `VariantGenerator` (eager widths only)
  - `ImageTag` (srcset from allowed widths)
- Tests: `packages/folio/tests/Assets/ImageWidthsTest.php`, plus extended `ImageTagTest` and `VariantGeneratorTest`

**Interfaces:**
- `ImageWidths::fromConfig(array $imageConfig, array $folioAssetsConfig)`:
  - `eager(): list<int>`: the union of every preset's `widths`, or `folio.assets.widths` if set (as today);
  - `forPreset(string $preset): list<int>`: the preset's widths plus its step widths between min and max, sorted and unique;
  - `allowed(): list<int>`: the union of all `forPreset`;
  - `srcsetWidths(string $preset, int $originalWidth): list<int>`: the preset's widths up to the original, plus the original when it is narrower than the preset's largest width.
- **ImageTag:**
  - builds each format's srcset from `srcsetWidths` and the formats the backend supports, using the deterministic file name `{base}-{w}.{fmt}` instead of the stored variant list;
  - for assets whose stored variants predate this change, the same names apply, so nothing changes for them;
  - an asset with no dimensions, or a non-raster image, is unchanged.
- **VariantGenerator:** at upload it makes the `eager()` widths, as today.

- [ ] **Step 1:** Tests:
  - `ImageWidthsTest`: step expansion (`[480, 960]`, step 160 gives 480, 640, 800, 960); the union; no upscaling in `srcsetWidths`; the original is added when narrower;
  - `ImageTagTest::test_srcset_lists_step_widths_up_to_the_original`;
  - `test_preset_without_step_is_unchanged`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Folio suite PASS.
- [ ] **Step 5:** Commit `feat(folio): presets can add step widths; srcset from the width model`.

### Task 4: On-demand variants

**Files:**
- Create: `packages/folio/src/Http/MediaVariantController.php`
- Modify:
  - `FolioRoutes` / `FolioServiceProvider`: the route `GET {assets.url}/{...path}`, registered before the frontend catch-all, so precedence is confirmed by a test;
  - `AssetLibrary`: a `variant(Asset, int $w, string $fmt): ?string` that generates under a `flock` on `{file}.lock`, writes temp + rename, and returns the path;
  - `remove()`: removes every `{base}-*.{avif,webp}` (a glob), not just the stored list;
  - `AssetsVariantsCommand`: a `--all` flag that also warms the step widths.
- Tests:
  - `packages/folio/tests/Http/MediaVariantControllerTest.php`
  - `packages/folio/tests/Integration/AssetFrontendTest.php` (extend)

**Interfaces:**
- **Path:** `^(\d{4}/\d{2}/[a-f0-9]{32})-(\d+)\.(avif|webp)$`.
  - The asset is looked up by id; its path base must equal the captured base.
  - The width must be in `allowed()`, at most the original width, and the format supported by the backend. Anything else is a 404, through `NotFoundHttpException`.
- **Response:** 200 with the bytes, `Content-Type`, `Cache-Control: public, max-age=31536000` and `Content-Length`.
- **Lock:** the lock file lives in the same directory and is removed after the write.

- [ ] **Step 1:** Tests:
  - `test_missing_allowed_variant_is_generated_and_served`: the file exists afterwards, and a second request reuses it (mtime unchanged);
  - `test_disallowed_width_unknown_asset_bad_format_and_traversal_are_404_and_write_nothing`;
  - `test_width_above_original_is_404`;
  - `test_concurrent_generation_encodes_once`: hold the lock from the test, start generation, assert it waits, then that it completes;
  - `test_other_missing_media_files_are_404`;
  - `test_frontend_catch_all_still_serves_pages`;
  - `test_delete_removes_lazy_variants`;
  - `test_warm_all_generates_step_widths`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the full framework suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat(folio): generate step-width image variants on first request`. Update the README "Asset library" section (step, priority, lazy variants, `--all`).

### Task 5: smeyer.me presets, release, verification

**Files:** smeyer.me `config/image.php`; framework tag `v0.17.0`.

- **Presets:**
  - `card`: widths `[480, 960, 1280]`, step 160, so 2× screens get up to 1280.
  - `portrait`: `priority: true`. It is the hero image, so the preload link is what improves LCP.
  - `club`: step 160.
- **Verification:**
  1. Run the full suite.
  2. Merge, push and tag `v0.17.0`, then wait for the split and Packagist.
  3. In smeyer.me: constraint `^0.17 || @dev`, lock, suite, push, deploy.
  4. On the live site:
     - `curl -I` on `/` gives 200;
     - the page has `fetchpriority="high"` and the preload link (once a portrait is uploaded; until then, no portrait means no link, as designed);
     - a step-width URL is a 200 on the first request and a static file afterwards;
     - a disallowed width is a 404;
     - Lighthouse is rerun.
