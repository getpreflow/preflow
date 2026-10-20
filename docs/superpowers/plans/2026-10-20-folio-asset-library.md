# Folio Asset Library Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A shared media library:
- uploads served straight from `public/media` by the web server;
- metadata: translatable alt, title, focal point, poster;
- eagerly generated WebP/AVIF width variants;
- a library screen with search, filters and usage;
- a picker in the existing drawer;
- `asset` fields that store ids;
- a `folio_image()` helper that emits `<picture>` with `srcset`;
- commands to regenerate variants and import legacy path values.

**Architecture:**
- **Storage and service:** assets are records of a code-defined `TypeDefinition`
  (`folio_assets`), read and written through the definition-based `DataManager` methods
  from the drafts-publishing plan. An `AssetLibrary` service owns upload, metadata, variants
  and deletion.
- **References:** one `AssetReferences` walker visits asset values inside top-level fields
  and nested blocks. It is used for both the usage scan and the legacy import rewrite.
- **Field:** the `asset` field keeps implementing `HandlesUpload`, so uploading without JS
  still works. Its submitted selection (`{name}_ids[]`) is authoritative, and it replaces
  the `_remove`/`_keep` inputs.

**Tech Stack:** PHP ≥ 8.4 with `fileinfo`; GD (preferred) or Imagick, optional; PHPUnit 11; Twig; vanilla ES5 `admin.js`.

**Spec:** `docs/superpowers/specs/2026-10-19-folio-editor-experience-design.md`, section 4c.

**Branch:** `feat/asset-library` from `main`, **after** `feat/drafts-publishing` is merged. It uses `DataManager::findIn/queryIn/deleteIn`, `Publisher`, `ShadowTypes`, `config/commands.php` discovery and `BootsApplication`.

## Global Constraints

- **Conventions:** `declare(strict_types=1)`; `final` classes and `final readonly` value
  objects; tests named `test_snake_case`.
- **CSP:** no inline handlers, `style=` attributes or un-nonced tags in admin or public
  output. Focal points are classes. JS may set `element.style` (CSSOM), which CSP allows.
- **Config** (`config/folio.php` `assets`):
  - `dir`: default `public/media`;
  - `url`: default `/media`;
  - `storage`: default `json`;
  - `max_bytes`: default `20971520`;
  - `allow_svg`: default `false`;
  - `widths`: default = the union of `config/image.php` `presets.*.widths`, else
    `[480, 768, 1024, 1600]`;
  - `quality`: default `75`.
- **Files:**
  - Originals are `{dir}/Y/m/{id}.{ext}`, where `id` is `bin2hex(random_bytes(16))`.
  - The extension comes from the **sniffed** MIME type (`finfo`), never from the client
    name.
  - Variants sit next to the original as `{id}-{width}.{webp|avif}`.
- **Allowed MIME types:**
  - images: `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/avif`, and
    `image/svg+xml` only when `allow_svg` is set;
  - videos: `video/mp4`, `video/webm`;
  - documents: `application/pdf`, `text/plain`, `text/csv`, `application/zip`.
- **Variants:**
  - only for jpeg, png, webp and avif;
  - at every configured width smaller than the original, plus the original width;
  - WebP always, AVIF when the backend supports it;
  - no variants and no error when no backend is available.
- **Video duration:** not read (null). No ffprobe.
- **Asset ids vs legacy values:** a stored value without `/` is an asset id. A value
  matching `Y/m/{32hex}.ext` is a legacy upload path and keeps rendering through `{prefix}/_uploads`.
- **Routes:** the library is at `{prefix}/assets`, which shadows a content type keyed
  `assets`. `TypeCatalog` refuses that key with a boot error that names the conflict.

## Review Focus

1. **A malicious upload:**
   - `shell.php` renamed to `.jpg`, or a polyglot, must be stored only under a sniffed,
     allowed MIME type with the matching extension;
   - an SVG must be refused unless `allow_svg` is set;
   - Task 1: `test_extension_follows_sniffed_mime`, `test_svg_refused_by_default`.
2. **A truncated or JS-less POST of a form with an asset field.** With no `{name}_ids[]`
   present, the stored selection must stay. With the marker present and empty, the
   selection is cleared (Task 6: `test_missing_selection_keeps_stored_value`).
3. **Duplicating a block that holds an asset.** The copy's `_ids[]` must be accepted for
   real asset ids and existing legacy paths, and forged paths must be rejected (Task 6:
   `test_selection_accepts_known_ids_and_stored_paths_only`).
4. **Deleting an asset used only in a published snapshot** (the working copy has
   already dropped it). The delete must be refused, because the live page still uses it
   (Task 4: `test_usage_includes_published_snapshots`).
5. **Running `folio:assets:import` twice, or a legacy path whose file is missing:**
   - each file is imported once;
   - references are rewritten in working copies, snapshots and revisions;
   - missing files stay as they are and are reported;
   - Task 7: `test_import_is_idempotent_and_reports_missing`.

---

### Task 1: Asset model and upload

**Files:**
- Create: `packages/folio/src/Assets/Asset.php`
  - `final readonly`: `id, path, originalName, mime, size, ?width, ?height, ?duration, array alt /*locale map*/, title, focalX = 50, focalY = 50, ?posterId, list<Variant> variants, createdAt, ?createdBy`.
  - `kind(): string` returns `image`, `video` or `document`.
  - `altFor(string $locale, string $default): string`.
  - `static fromRecord(DynamicRecord)` and `toData(): array`.
- Create: `packages/folio/src/Assets/Variant.php` (`final readonly`: `int $width`, `string $format`, `string $path`).
- Create: `packages/folio/src/Assets/AssetUploadException.php`.
- Create: `packages/folio/src/Assets/AssetLibrary.php`
  - `__construct(DataManager $dm, Locales $locales, string $dir, string $url, array $config, ?VariantGenerator $variants = null, ?\Closure $now = null)`.
  - `definition(): TypeDefinition` returns key `_asset`, table `folio_assets`, fields
    matching `Asset`, and `alt` translatable.
  - `upload(UploadedFileInterface $file, ?string $by): Asset`
  - `importFile(string $absPath, string $originalName, ?string $by): Asset` copies the file
    and leaves the source in place.
  - `find(string $id): ?Asset`
  - `all(): list<Asset>`, newest first.
  - `update(string $id, array $meta): Asset` accepts `alt` (map), `title`, `focal_x`,
    `focal_y` (clamped 0–100), and `poster` (an image asset id or null).
  - `delete(string $id): void` removes the record, the original and the variants.
  - `url(string $relativePath): string`
  - `isAssetId(string $value): bool` checks `/^[a-f0-9]{32}$/` and that the record exists.
- Modify: `FolioServiceProvider`, which binds `AssetLibrary` (singleton) from config.
- Test: `packages/folio/tests/Assets/AssetLibraryTest.php`. Real PNG/JPEG bytes are made
  with GD in a helper (`imagecreatetruecolor(1200, 800)` → `imagepng`). The class is
  marked `#[RequiresPhpExtension('gd')]` where bytes are needed.

- [ ] **Step 1: Write the failing tests:**
  - `test_upload_creates_asset_with_dimensions`: 1200×800 PNG → `width === 1200`, `height === 800`, `mime === 'image/png'`, file at `{dir}/Y/m/{id}.png`, `find(id)` round-trips.
  - `test_extension_follows_sniffed_mime`: PNG bytes named `shell.php` → stored `.png`; the text `<?php echo 1;` named `x.jpg` → stored as `text/plain` with `.txt`.
  - `test_svg_refused_by_default`: `AssetUploadException` "SVG uploads are disabled"; accepted with `allow_svg: true`.
  - `test_size_limit`: `max_bytes: 10` → `AssetUploadException` mentioning the limit.
  - `test_update_metadata_clamps_focal_point`: `focal_x: 150` → 100; an alt map merges per locale.
  - `test_delete_removes_files`.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/folio/tests/Assets/AssetLibraryTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** Dimensions come from `getimagesize`.
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): asset library model and sniffed uploads`.

### Task 2: Image variants

**Files:**
- Create: `packages/folio/src/Assets/Image/ImageBackend.php`
  - `interface { supports(string $format): bool; resize(string $src, string $dest, int $width, string $format, int $quality): bool; }`
- Create: `GdBackend.php` and `ImagickBackend.php` (same dir), and
  `ImageBackends::detect(): ?ImageBackend`, which prefers GD, then Imagick, else null.
- Create: `packages/folio/src/Assets/VariantGenerator.php`
  - `__construct(?ImageBackend $backend, list<int> $widths, int $quality)`.
  - `generate(string $absSource, string $mime, int $originalWidth): list<Variant>`
  - `remove(Asset $asset): void`
- Modify: `AssetLibrary::upload/importFile`, which generate variants for images.
- Add `AssetLibrary::regenerate(Asset): Asset`, which removes the old variants and
  generates new ones.
- Test: `packages/folio/tests/Assets/VariantGeneratorTest.php`.

- [ ] **Step 1: Write the failing tests:**
  - `test_generates_webp_widths_below_original`: widths `[480, 768, 1600]` on a 1200 px PNG → webp at 480, 768 and 1200; files exist and `getimagesize` of the 480 one gives width 480.
  - `test_avif_when_supported`: skipped unless `imageavif` exists.
  - `test_no_backend_means_no_variants`: `new VariantGenerator(null, …)` → `[]`, and the upload still succeeds.
  - `test_gif_and_documents_get_no_variants`.
  - `test_regenerate_replaces_variants`: change the widths, regenerate → old files are gone and new ones exist.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): eager webp/avif image variants via GD or Imagick`.

### Task 3: `folio_image()` and frontend rendering

**Files:**
- Create: `packages/folio/src/Assets/ImageTag.php`
  - `__construct(AssetLibrary, ContentLocale, array $presets /* config image.presets */, AssetCollector $css, string $legacyUrlPrefix)`.
  - `render(mixed $value, ?string $preset = null, array $options = []): string`
    - `$value` is an id, a legacy path, a JSON list or a PHP list; the first item is used.
    - Options come from `ResponsiveImage::DEFAULTS` merged with the preset and then the
      call options (`sizes`, `class`, `loading`, `alt` override).
    - It emits `<picture>` with an AVIF `<source>` (if there are avif variants), a WebP
      `<source>`, and `<img src="{original}" width height alt loading [class]>`.
    - A non-image asset → `<a href>` with its title or original name.
    - A legacy path → `<img src="{prefix}/_uploads/{path}" alt="">`.
    - Unknown → `''`.
  - **Focal point:** rounded to steps of 5. When it is not 50/50 it adds the classes
    `folio-focal folio-fx-{x} folio-fy-{y}`, and registers once (key `folio-focal`) via
    `AssetCollector::addCss`:
    - `.folio-focal{object-position:var(--folio-fx,50%) var(--folio-fy,50%)}`
    - 21 `.folio-fx-N{--folio-fx:N%}` rules
    - 21 `.folio-fy-N{--folio-fy:N%}` rules
- Modify: `FolioServiceProvider`, registering the Twig function `folio_image` (safe).
- Modify: `AssetFieldType::renderFrontend`, which uses `ImageTag::render` for its value.
- Test: `packages/folio/tests/Assets/ImageTagTest.php`.

- [ ] **Step 1: Write the failing tests:**
  - `test_picture_with_srcset_and_dimensions`: it contains `<source type="image/webp" srcset="/media/…-480.webp 480w, /media/…-768.webp 768w, /media/…-1200.webp 1200w"`, and `width="1200" height="800"`.
  - `test_alt_follows_content_locale`: alt `{en: "Sea", de: "Meer"}` with locale de → `alt="Meer"`; with no German value → "Sea".
  - `test_focal_point_class_not_style`: focal 30/70 → `folio-focal folio-fx-30 folio-fy-70`, no `style=`, and the collector has the CSS once after two renders.
  - `test_preset_options_apply`: the preset `hero` with `sizes: '50vw'`, `loading: 'eager'` gives those attributes.
  - `test_legacy_path_and_unknown`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): folio_image picture helper with focal classes`.

### Task 4: References walker and usage

**Files:**
- Create: `packages/folio/src/Assets/AssetReferences.php`
  - `__construct(FieldTypeRegistry, BlockRegistry)`.
  - `map(TypeDefinition $def, array $data, \Closure $fn): array`
    - `$fn(string $value): string` is applied to every asset value (each item of a list).
    - It returns the rewritten data.
    - It walks top-level `asset` fields and, inside `blocks` fields, each block's
      definition recursively.
    - Unknown blocks are left as they are.
  - `collect(TypeDefinition $def, array $data): list<string>`, built on `map`.
- Create: `packages/folio/src/Assets/AssetUsage.php`
  - `__construct(AssetReferences, DataManager, TypeCatalog, TypeRegistry, AssetLibrary, string $prefix)`.
  - `of(string $assetId): list<array{type: string, id: string, label: string, url: string, where: 'working'|'published'|'poster'}>`
  - It scans every catalog type's working copies plus, for publishing types, snapshots
    (`ShadowTypes::published`), plus assets whose poster is the id.
- Test: `packages/folio/tests/Assets/AssetUsageTest.php`, with blocks fixtures that
  contain an asset (`TradeoffNoteBlock`).

- [ ] **Step 1: Write the failing tests:**
  - `test_collects_top_level_and_nested_block_assets`.
  - `test_map_rewrites_lists_and_nested_values`.
  - `test_usage_includes_published_snapshots`: the working copy has no asset but the snapshot has one → one entry with `where === 'published'`.
  - `test_poster_usage`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): asset reference walker and usage scan`.

### Task 5: Library screen

**Files:**
- Create: `packages/folio/src/Http/AssetLibraryController.php`
  - **`index`** (GET `{p}/assets`):
    - query parameters: `q`, `kind` (`image|video|document`), `view` (`grid|list`),
      `page` (48 per page), and `picker=1&multiple=0|1`;
    - search is a case-insensitive substring over the original name, the title and all
      alt values;
    - in picker mode it uses `_drawer_layout.twig`.
  - **`upload`** (POST `{p}/assets`, `files[]`): per-file errors are listed. Without
    picker mode it redirects 302 to the library; in picker mode it redirects to the picker
    with `selected=` set to the new ids.
  - **`panel`** (GET `{p}/assets/{id}`):
    - the metadata form, with one alt input per locale, the title, a hidden
      `focal_x`/`focal_y`, an image with `data-folio-focal`, and a poster `<select>` of
      image assets for videos;
    - the usage list;
    - a delete form.
  - **`save`** (POST `{p}/assets/{id}`) and **`destroy`** (POST `{p}/assets/{id}/delete`).
    A used asset gives 409 with "Used in N records" plus the linked list, and nothing is
    deleted.
- Templates: `packages/folio/templates/admin/assets/{index,panel,_grid,_list}.twig`. Picker mode adds `data-folio-pick` buttons.
- Modify: `FolioRoutes::admin`, adding the five rows before `{p}/{type}`. Navigation gets
  an "Assets" link in `_layout.twig`.
- Modify: `TypeCatalog`. A type keyed `assets` → `\LogicException` naming the route
  conflict.
- Modify: `admin.js`
  - a focal picker: clicking the image sets the inputs to the rounded percentages and moves
    the marker via `element.style`;
  - in picker mode, `[data-folio-pick]` posts
    `{source: 'folio-assets', items: [{id, thumb, label}]}` to the parent (same origin);
  - a library-panel open via htmx (`hx-get` the panel into `#folio-asset-panel`).
- Modify: `admin.css`, adding the grid, list, panel and focal marker.
- Test: `packages/folio/tests/Integration/AssetLibraryAppTest.php`, plus `AdminJsTest` and `CspGuardTest` coverage of the new templates.

- [ ] **Step 1: Write the failing tests:**
  - `test_multi_upload_lists_assets`: two PNGs → 302; the index shows two `folio-asset` items.
  - `test_search_and_kind_filter`: `q=sea` matches by German alt; `kind=document` hides images.
  - `test_panel_saves_metadata`: alt en/de, title and focal 30/70 are stored.
  - `test_delete_used_asset_is_refused`: 409 containing "Used in 1 record" and the record label; the asset still exists. An unused one → 302 and it is gone.
  - `test_picker_mode_uses_drawer_layout_and_pick_buttons`.
  - `test_type_named_assets_is_a_boot_error`.
  - `AdminJsTest`: the focal and pick handlers exist.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS, with `CspGuardTest` green.
- [ ] **Step 5: Commit** `feat(folio): asset library screen with metadata, focal point and usage`.

### Task 6: `asset` fields store ids, with a picker

**Files:**
- Modify: `AssetFieldType`
  - Constructor `(AssetLibrary $library, string $legacyUploadsDir, string $legacyUrlPrefix, string $adminPrefix)`.
  - `selectionName(string $name): string`: `cover` → `cover_ids`, and
    `a[b][cover]` → `a[b][cover_ids]`.
  - `resolve(?array $submittedIds, mixed $existing, array $uploads, array $config, bool $writeUploads, ?string $by): mixed`
    1. A null `$submittedIds` keeps the existing value.
    2. Otherwise it keeps, in order, the submitted ids that pass `library->isAssetId()`,
       plus legacy paths that `isStoredUpload()`.
    3. It drops empty values.
    4. It appends the uploads, stored via `library->upload()` when `$writeUploads` is set
       and the file passes the field's `accept` kind.
    5. It applies `multiple` (single: the last value wins) and returns the storage value.
  - `storeUploads` (`HandlesUpload`) delegates to `resolve`.
  - `renderEditor` shows:
    - a hidden `{selection}[]` with value `""` first, as the marker;
    - one hidden `{selection}[]` per selected value;
    - thumbnails (the 480 variant, else the original, else the legacy URL, else a file
      icon) with a remove button (`data-folio-asset-remove`);
    - "Choose" (`data-folio-asset-choose`, `data-picker-url="{p}/assets?picker=1&multiple=…"`);
    - "Upload" (`<input type="file" name="{name}">`).
  - Remove `removeName`/`keepName`. `isStoredUpload` stays.
- Modify: `DraftRecordFactory` (the `HandlesUpload` branch) and `BlocksFieldType` (the
  asset handling at `:107-119`). Both call `resolve()` with the selection from POST: null
  when the `{selection}` key is absent.
- Modify: `admin.js`:
  - "Choose" opens the drawer with the picker URL and remembers the field;
  - `onMessage` handles `source === 'folio-assets'` by appending the hidden inputs and
    thumbnails (replacing them for single fields), then closes the drawer and dispatches
    `change`;
  - remove deletes the item's hidden input and thumbnail, then dispatches `change`.
- Test: update `packages/folio/tests/Field/AssetFieldTypeTest.php`, `DraftRecordFactoryTest`, the asset cases in `BlocksFieldTypeTest`, `FolioAppTest` upload test; add `AdminJsTest` cases.

- [ ] **Step 1: Write or adjust the failing tests:**
  - `test_upload_goes_into_library_and_stores_id`: POST a file → the stored value is a 32-hex id, `library->find(id)` exists, and the file is under `public/media`.
  - `test_selection_order_is_kept`: `cover_ids[] = ['', idB, idA]` with multiple → `[idB, idA]`.
  - `test_missing_selection_keeps_stored_value`: no `cover_ids` key → unchanged; `cover_ids = ['']` → cleared.
  - `test_selection_accepts_known_ids_and_stored_paths_only`: an unknown id, `../../etc/passwd` and a non-existing legacy path are dropped; an existing legacy path is kept.
  - `test_blocks_asset_uses_selection`: the nested name `sections[uid][cover_ids][]` → id stored in the block.
  - `test_legacy_value_renders_in_editor_and_frontend`.
  - `AdminJsTest`: the choose, remove and message handlers exist; the `folio-assets` origin is checked.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Folio`. Expected: FAIL in the listed tests.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): asset fields store library ids, with picker and upload`.

### Task 7: Commands: variants and legacy import

**Files:**
- Create: `packages/folio/src/Console/AssetsVariantsCommand.php`
  - `folio:assets:variants [--id=<id>]` runs `library->regenerate()` for every image (or
    one).
  - It prints a count and warns "No GD or Imagick: variants skipped" when there is no
    backend.
- Create: `packages/folio/src/Console/AssetsImportCommand.php`
  - `folio:assets:import [--dry-run]`.
  - For every catalog type it covers working copies, snapshots and revisions (the revision
    `data` key).
  - It runs `AssetReferences::map`:
    - a legacy path whose file exists under the legacy uploads dir → `importFile` once per
      path (memoized map), and the value becomes the id;
    - a missing file stays as it is and is reported.
  - It saves through the definition-based `saveType(validate: false)` without touching
    `_version`.
  - It prints `Imported N files, rewrote M records, K missing`.
  - `--dry-run` writes nothing.
- Modify: `FolioCommands::all()`, adding both commands.
- Test: `packages/folio/tests/Console/AssetCommandsTest.php`.

- [ ] **Step 1: Write the failing tests:**
  - `test_variants_command_regenerates`.
  - `test_import_rewrites_working_snapshot_and_revision`: a legacy cover in all three plus a nested block → all four become the same id; one asset is created.
  - `test_import_is_idempotent_and_reports_missing`: a second run imports 0; a missing path is listed and its value is unchanged.
  - `test_dry_run_writes_nothing`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(folio): asset variant regeneration and legacy import commands`.

### Task 8: Demo, docs, browser check

**Files:**
- Modify: the demo:
  - `examples/folio-demo/public/media/.gitkeep`, with `public/media/*` gitignored except
    `.gitkeep`;
  - the `Hero` block gets an `image` asset field rendered with
    `{{ folio_image(image, 'hero') }}`;
  - `config/image.php` gets a `hero` preset;
  - `seed.php` imports one bundled sample image (generated with GD at seed time if absent)
    into the library and uses it in the hero.
- Verify that the demo's PHP built-in-server router serves files under `public/media`
  directly; adjust `public/index.php` to `return false` for existing static files under
  `cli-server` if needed.
- Modify: the Folio docs, adding an "Asset library" section:
  - the config keys;
  - the variants and the `folio:assets:variants` command;
  - `folio_image()` with focal points (the app CSS needs `object-fit: cover` for focal
    points to matter);
  - the picker;
  - `folio:assets:import`;
  - the SVG policy;
  - the SQL schema for `folio_assets`.
- Test: the demo test also asserts that the home page contains `<picture>` and a `/media/` srcset after seeding.

- [ ] **Step 1: Extend the demo test.** Run it; Expected: FAIL.
- [ ] **Step 2: Update the demo.** Run `vendor/bin/phpunit`; Expected: PASS.
- [ ] **Step 3: Write the docs section.**
- [ ] **Step 4: Browser check** on the demo:
  - upload two images in the library;
  - set alt (en/de) and the focal point;
  - choose an image in the Hero block via the picker drawer, save, and check that the live
    page has `<picture>` with WebP/AVIF sources;
  - deleting a used asset is refused;
  - the CSP shows no violations.
- [ ] **Step 5: Commit** `docs(folio): asset library; demo uses library images`, and
  update `docs/superpowers/plans/README.md` with row 16.
