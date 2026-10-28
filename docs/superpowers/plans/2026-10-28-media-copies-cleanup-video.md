# Media: Copies, Cleanup, Video Previews Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Copies of assets that know their original and can be remade in place, a bin for every delete, an opt-in
cleanup of unused assets, private imports, and video previews from one frame — in the media core, with Folio's
library as the UI.

**Architecture:** Everything lives in `preflow/media` (core, no Folio dependency) behind public methods on
`MediaLibrary` plus small registries (`DerivedMakers`) and usage sources (`CopyReferences`). The "is it used"
question stays the existing `AssetUsage` registry: copies become one more usage source, Folio's source learns
revisions. Folio only renders stacks, panels, buttons and the bin view.

**Tech Stack:** PHP 8.4, PHPUnit 11, Twig (Folio admin), GD/Imagick (existing), ffmpeg/ffprobe (optional binary).

**Spec:** `docs/superpowers/specs/2026-10-28-media-copies-cleanup-video-design.md`

## Global Constraints

- PHP 8.4 in production (local is 8.5): no 8.5-only syntax.
- `preflow/media` must not depend on `preflow/folio` (`packages/media/tests/NoFolioDependencyTest.php` stays green).
- Breaking changes allowed in 0.x; release as **v0.43.0**; media and folio constraints bumped to `^0.43 || @dev`
  where they use the new API, `conflict` `<0.43` accordingly (`ComposerConstraintsTest` in both packages).
- New asset columns come from `MediaLibrary::definition()` fields; `RecordTableSync` adds missing columns
  (typed per 0.42: `binned_at` and `created_at`-like values are `string`, `derived_version` is `number`).
- External programs (ffmpeg, ffprobe) run with arguments as a list (no shell) and a time limit, like
  `PdfPreviewRenderer::run()`.
- Cleanup and the bin cover **every kind** of asset (image, video, document, other), public and private (owner).
- Cleanup is **opt-in**: `config/media.php` `'cleanup' => ['enabled' => false, 'min_age_days' => 7, 'bin_days' => 30]`.
- Every delete goes to the bin when `bin_dir` is set (owner). Without `bin_dir`, delete keeps today's behaviour and
  a warning appears (migrate and Folio library).
- German admin texts use "du" (Folio admin convention); public texts are unaffected (none here).
- Commands that walk the library are chunked (`ChunkOptions`: `--limit`, `--offset`), the live server stops long jobs.

## Rulings made while planning (from the spec, recorded for executors)

- **Copies protect originals through a usage source.** `CopyReferences` (core, registered `last: true` next to
  `PosterReferences`) reports every live (non-binned) copy as a usage of its original. That gives three spec rules
  at once: delete of an original with copies is refused, cleanup treats it as used, emptying the bin skips it.
- **Revision usages don't block a manual delete.** `AssetUsageEntry` gains `public bool $blocksDelete = true`.
  Folio's revision entries set it false: a manual delete still bins the asset (restorable). Cleanup and
  `media:bin --empty` treat **every** entry, blocking or not, as "used", so nothing a revision needs is ever
  deleted for good.
- **The maker `copy` is built in and reserved:** it takes the original's raw file and the original's crop/rotation/
  flip as the copy's starting settings. Every other maker gets the original **as people see it**: turned and
  mirrored, without the crop (the file `orientedUrl()` serves, or the raw file for an unedited or non-image original).
- **"Original changed" = version mismatch.** A copy stores `derived_version` (the original's `version` when it was
  made or remade). `MediaLibrary::isStale(Asset $copy): bool` is true when the original's version is higher.
- **`find()` hides binned assets.** Pages, pickers and `media_image()` never resolve them; `findBinned()` and
  `binned()` reach them.

## Review Focus

1. A binned asset referenced from a page: `media_image()` returns '' and `url()` callers get no broken file path —
   test in Task 3 (`test_binned_asset_does_not_resolve`).
2. Emptying the bin while a revision still references an asset: the asset stays in the bin with its reason — test in
   Task 3 (`test_empty_skips_asset_that_a_source_still_reports`, with a non-blocking entry).
3. A video ffmpeg can't read (truncated MP4, zero length): upload succeeds, no preview, no exception — test in Task 5
   (`test_unreadable_video_gives_no_preview`).
4. Remake when the original is binned or gone: refused with a clear message, the copy unchanged — test in Task 2
   (`test_remake_refuses_without_live_original`).
5. Copying a copy keeps the watermark: sibling copy with the same maker and params — test in Task 1
   (`test_copy_of_copy_is_sibling_with_same_maker`).

---

### Task 1: Copies in the core (`derived_from`), private import, stacked queries

**Files:**
- Modify: `packages/media/src/Asset.php` (new properties, `TEXT_COLUMNS`/`RESERVED`, `toData()`)
- Modify: `packages/media/src/MediaLibrary.php` (`definition()`, `importFile()`, `derive()`, `makeCopy()`, `copies()`, `copyCounts()`, `query()`, record mapping)
- Create: `packages/media/src/Usage/CopyReferences.php`
- Modify: `packages/media/src/MediaServiceProvider.php` (register `CopyReferences` last)
- Test: `packages/media/tests/CopiesTest.php`, `packages/media/tests/QueryTest.php`, `packages/media/tests/QuerySqlTest.php`

**Interfaces:**
- Produces:
  - `Asset::$derivedFrom: ?string`, `Asset::$maker: ?string`, `Asset::$makerParams: array<string, mixed>`, `Asset::$derivedVersion: ?int`, `Asset::isCopy(): bool`
  - Columns `derived_from`, `maker`, `maker_params` (JSON text), `derived_version`
  - `MediaLibrary::importFile(string $absPath, string $originalName, ?string $by, bool $private = false): Asset`
  - `MediaLibrary::derive(string $originalId, string $file, ?string $by, ?string $maker = null, array $params = [], ?bool $private = null): Asset` — `$file` an absolute path; `$private` null = the original's flag; stores `derived_version` = original's version; a copy's original id is always a root (an id that is itself a copy resolves to its `derivedFrom`)
  - `MediaLibrary::makeCopy(string $id, ?string $by): Asset` — on an original: maker `copy`, raw file, the original's `rotation`, `flip`, `crop`, `crops`, `focal_x`, `focal_y` copied; on a copy: a sibling with the same original, maker, params, file and edit settings as that copy
  - `MediaLibrary::copies(string $originalId): list<Asset>` newest first, non-binned
  - `MediaLibrary::copyCounts(list<string> $ids): array<string, int>` (one query)
  - `MediaLibrary::query(?string $kind = null, string $search = '', array $filters = [], int $page = 1, int $perPage = 50, ?bool $copies = null): AssetPage` — `$copies` null: copies hidden unless `$search !== ''`; true: included; false: hidden
  - `CopyReferences implements AssetReferenceSource`, name `copies`; label = the copy's label, url null, note `copy`

- [ ] **Step 1: Failing tests** in `CopiesTest`:
  - `test_derive_links_copy_to_original` — derive from a fixture JPEG: `derivedFrom === $original->id`, `maker === 'watermark'`, `makerParams === ['line' => 'x']`, `derivedVersion === 1`, file stored as a normal asset.
  - `test_derive_from_a_copy_points_at_the_root`.
  - `test_make_copy_of_original_takes_raw_file_and_edits` — rotate the original once, `makeCopy()`: copy `rotation === 1`, `maker === 'copy'`, its file bytes equal the original's file bytes.
  - `test_copy_of_copy_is_sibling_with_same_maker` — derive with maker `watermark`, `makeCopy($copy->id)`: `derivedFrom === $original->id`, `maker === 'watermark'`, same params, file bytes equal the copy's.
  - `test_copies_lists_newest_first` and `test_copy_counts`.
  - `test_delete_of_original_with_copies_is_refused` — `AssetInUseException` whose usages contain source `copies`.
  - `test_import_file_private` — `importFile($path, 'a.pdf', null, private: true)->private === true` and the file is under `private_dir`.
- [ ] **Step 2:** Run `vendor/bin/phpunit packages/media/tests/CopiesTest.php` — FAIL (undefined method `derive`).
- [ ] **Step 3:** Implement the properties, columns, methods and `CopyReferences` (registered in `MediaServiceProvider` with `last: true`, before `PosterReferences` or after — order not significant). Store via the existing `store()` path; `makeCopy()` reads the source asset's file with `file()`.
- [ ] **Step 4:** Failing query tests in `QueryTest` (JSON) and `QuerySqlTest` (SQLite): `test_copies_hidden_by_default`, `test_search_finds_copies`, `test_copies_true_includes_them`; `total` counts match the visible items.
- [ ] **Step 5:** Implement the `query()` condition (`derived_from` empty or NULL unless shown). Run the whole media suite: `vendor/bin/phpunit --testsuite Media` — PASS.
- [ ] **Step 6:** Commit `feat(media): copies know their original (derive, makeCopy, copies) and private imports`.

### Task 2: Makers and remake

**Files:**
- Create: `packages/media/src/Derived/DerivedMaker.php`, `packages/media/src/Derived/DerivedMakers.php`
- Modify: `packages/media/src/MediaLibrary.php` (`remake()`, `isStale()`), `packages/media/src/MediaServiceProvider.php` (bind `DerivedMakers` as a shared singleton, pass to `MediaLibrary`)
- Test: `packages/media/tests/RemakeTest.php`

**Interfaces:**
- Consumes: Task 1's `derive()`, `Asset::$maker`, `$makerParams`, `$derivedVersion`.
- Produces:
  - `interface DerivedMaker { public function name(): string; public function make(Asset $original, string $file, array $params): string; }` — `$file` the oriented original (see Rulings); returns the absolute path of a new temp file the library then owns (deletes after use)
  - `DerivedMakers::register(DerivedMaker $maker): void` (throws `\InvalidArgumentException` for the reserved name `copy`), `DerivedMakers::get(string $name): ?DerivedMaker`, `DerivedMakers::has(string $name): bool`
  - `MediaLibrary::remake(string $copyId, ?string $by, ?array $params = null): Asset` — same id; through `replace()`'s path, so the earlier file becomes a version (needs `versions_dir`, as replace does); updates `maker_params` when `$params` given and `derived_version` to the original's version
  - `MediaLibrary::canRemake(Asset $copy): bool`, `MediaLibrary::isStale(Asset $copy): bool`

- [ ] **Step 1: Failing tests** in `RemakeTest` (a fake maker writing a marker into a copied JPEG):
  - `test_remake_keeps_id_and_versions_old_file` — `remake()` returns the same id, `version === 2`, `versions()` has 1 entry.
  - `test_remake_with_new_params_stores_them`.
  - `test_remake_gives_maker_the_oriented_original` — original rotated once: the fake maker receives a file whose width/height are swapped against the raw file.
  - `test_remake_copy_maker_uses_raw_file_and_keeps_copy_edits` — copy crop set, original replaced, remake: copy's file bytes equal the new original file, crop unchanged.
  - `test_remake_refuses_unknown_maker` and `test_remake_refuses_without_live_original` (original binned in Task 3; here: original deleted via `discard()`): `\InvalidArgumentException`, copy unchanged.
  - `test_is_stale_after_original_replaced`, false again after remake.
  - `test_register_copy_name_is_refused`.
- [ ] **Step 2:** Run `vendor/bin/phpunit packages/media/tests/RemakeTest.php` — FAIL.
- [ ] **Step 3:** Implement. The oriented file: reuse whatever `orientedUrl()` renders (the method's private helper that writes the oriented image), at the original's full size, not `ORIENTED_LONG_SIDE` — add a parameter if the helper caps it.
- [ ] **Step 4:** `vendor/bin/phpunit --testsuite Media` — PASS.
- [ ] **Step 5:** Commit `feat(media): remake a copy from its original in place (DerivedMaker)`.

### Task 3: The bin

**Files:**
- Modify: `packages/media/src/MediaConfig.php` (`bin_dir`, `cleanup` array; `bin_dir` must be outside served dirs like `versions_dir`)
- Modify: `packages/media/src/MediaLibrary.php` (`delete()`, `find()`, `all()`, `query()`, `findBinned()`, `binned()`, `restoreFromBin()`, `emptyBin()`, `binDir()`)
- Modify: `packages/media/src/Usage/AssetUsageEntry.php` (`blocksDelete`), `packages/media/src/Usage/AssetUsage.php` (`blocking(string $id): list<AssetUsageEntry>`)
- Modify: `packages/media/src/MediaServiceProvider.php` (migrate warning when `bin_dir` missing)
- Create: `packages/media/src/Console/BinCommand.php` (listed in `composer.json` `extra.preflow.commands`)
- Test: `packages/media/tests/BinTest.php`, `packages/media/tests/Console/BinCommandTest.php`, `packages/media/tests/MediaConfigTest.php`, `packages/media/tests/MediaLibraryDeleteTest.php`

**Interfaces:**
- Consumes: Task 1's `CopyReferences`, `derivedFrom`.
- Produces:
  - `Asset::$binnedAt: ?string` (column `binned_at`, ISO 8601)
  - `MediaLibrary::delete(string $id): void` — refuses on **blocking** usages (`AssetInUseException`); with `bin_dir`: moves the asset's file, versions, previews, master and variants into `{bin_dir}/{id}/` and sets `binned_at`; without `bin_dir`: today's removal
  - `MediaLibrary::findBinned(string $id): ?Asset`, `MediaLibrary::binned(int $page = 1, int $perPage = 50): AssetPage` newest-binned first
  - `MediaLibrary::restoreFromBin(string $id): Asset` — files back, `binned_at` cleared, derived files made again; a copy whose original is binned restores the original first; a copy whose original no longer exists gets `derived_from`, `maker`, `maker_params`, `derived_version` cleared
  - `MediaLibrary::emptyBin(int $olderThanDays, ?\Closure $progress = null): BinEmptyResult` — deletes for good what is binned longer than `$olderThanDays`, **except** anything `AssetUsage::of()` still reports (blocking or not); `BinEmptyResult { int $deleted; list<array{id: string, reason: string}> $kept; }`
  - `preflow media:bin` (list), `--restore=<id>`, `--empty` (uses `cleanup.bin_days`, default 30), `--limit/--offset`

- [ ] **Step 1: Failing tests** in `BinTest`:
  - `test_delete_moves_to_bin` — file gone from the public dir, present under `bin_dir/{id}/`, `find()` null, `findBinned()` set.
  - `test_binned_asset_does_not_resolve` — `media_image()` (via `ImageTag`) returns `''`; `query()` doesn't list it.
  - `test_restore_brings_back_file_and_variants`.
  - `test_restore_copy_restores_binned_original`; `test_restore_copy_of_gone_original_clears_link`.
  - `test_binned_copy_does_not_block_binning_original`.
  - `test_empty_deletes_old_binned_for_good` (clock via the `now` closure: 31 days later); `test_empty_keeps_recent`.
  - `test_empty_skips_asset_that_a_source_still_reports` — a test usage source returning an entry with `blocksDelete: false`: the asset stays, `kept[0]['reason']` names the source.
  - `test_empty_skips_original_with_live_copy`.
  - `test_delete_without_bin_dir_removes_as_before`.
  - `test_non_blocking_usage_lets_delete_bin` and `test_blocking_usage_refuses`.
  - In `MediaConfigTest`: `bin_dir` inside `public/` is refused; `cleanup` defaults `enabled false, min_age_days 7, bin_days 30`.
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement. Use the per-asset lock (`locked()`) for bin and restore. Private assets bin the same way (their files come from `private_dir`, go back there).
- [ ] **Step 4:** `BinCommandTest`: list prints id, name, kind, binned date; `--restore` restores; `--empty` prints deleted and kept counts with reasons; refuses with a clear line when `bin_dir` is not set. Implement, run — PASS.
- [ ] **Step 5:** Migrate warning: when `bin_dir` is null, the media table step prints once: `media: bin_dir is not set; deleted assets are removed for good (see the media README)`. Test in `MediaTableStepTest`.
- [ ] **Step 6:** `vendor/bin/phpunit --testsuite Media` — PASS. Commit `feat(media): every delete goes to a bin (restore, empty after bin_days)`.

### Task 4: Cleanup of unused assets

**Files:**
- Create: `packages/media/src/Cleanup/AssetCleanup.php`, `packages/media/src/Cleanup/CleanupCandidate.php`, `packages/media/src/Console/CleanupCommand.php` (in `composer.json` commands)
- Test: `packages/media/tests/Cleanup/AssetCleanupTest.php`, `packages/media/tests/Console/CleanupCommandTest.php`

**Interfaces:**
- Consumes: `AssetUsage::of()`, `AssetUsage::sourceNames()`, Task 3's `delete()` (bin) and `cleanup` config.
- Produces:
  - `AssetCleanup::candidates(array $filters = [], int $offset = 0, ?int $limit = null): list<CleanupCandidate>` — filters `kind` (image|video|document|other), `by` (created_by), `private` (bool), `older_than_days` (int, default `min_age_days`)
  - `CleanupCandidate { Asset $asset; string $reason; }` — reason e.g. `no source reports it (asked: folio, copies, posters)`
  - `AssetCleanup::isEnabled(): bool`
  - `preflow media:cleanup [--delete] [--kind=] [--by=] [--private|--public] [--older-than=<days>] [--limit= --offset=]`

- [ ] **Step 1: Failing tests** in `AssetCleanupTest`:
  - `test_unused_old_asset_is_candidate` (every kind: one image, one PDF, one MP4 fixture, one TXT).
  - `test_used_asset_is_not_candidate` (test source reports it); `test_non_blocking_usage_also_protects`.
  - `test_recent_upload_is_not_candidate` (6 days old with `min_age_days` 7).
  - `test_original_of_live_copy_is_not_candidate`; `test_poster_is_not_candidate`.
  - `test_filters_by_kind_by_and_private`.
  - `test_binned_assets_are_not_candidates`.
- [ ] **Step 2:** Run — FAIL. **Step 3:** Implement. **Step 4:** Run — PASS.
- [ ] **Step 5:** `CleanupCommandTest`: disabled config → exit 1 with `Cleanup is off (config/media.php cleanup.enabled). Read the media README first: cleanup only knows usages reported by usage sources.`; enabled without `--delete` lists candidates and deletes nothing; `--delete` bins them and prints the count; `--delete` without `bin_dir` refuses (cleanup never deletes for good). Implement, run — PASS.
- [ ] **Step 6:** Commit `feat(media): opt-in cleanup of unused assets into the bin`.

### Task 5: Video previews

**Files:**
- Create: `packages/media/src/Preview/VideoPreviewRenderer.php`
- Modify: `packages/media/src/MediaConfig.php` (`ffmpeg`, `ffprobe` paths), `packages/media/src/MediaServiceProvider.php` (register as fallback renderer), `packages/media/src/Console/PreviewsCommand.php` (videos too; texts "file" instead of "document"), `packages/media/src/MediaLibrary.php` (`thumbnailUrl()`: a video's or document's image poster wins over its preview), `packages/media/src/ImageTag.php` (the poster/preview branch covers videos)
- Create fixture: `packages/media/tests/Fixtures/video/clip.mp4` (2 s, 160×90, generated once with ffmpeg and committed, under 50 KB) and `broken.mp4` (first 1 KB of clip.mp4)
- Test: `packages/media/tests/Preview/VideoPreviewRendererTest.php`, `packages/media/tests/Console/PreviewsCommandTest.php`, `packages/media/tests/ImageTagTest.php`

**Interfaces:**
- Produces: `VideoPreviewRenderer implements PreviewRenderer` — `supports()` for `video/mp4`, `video/webm`, `video/quicktime`; `render(string $file, int $longSide): ?string` PNG temp path or null; frame at 1 s, or at 10 % of the duration when the video is shorter than 10 s (duration via ffprobe; without ffprobe, 1 s and on failure frame 0); 20 s time limit. Binaries: config path, else `PdfPreviewRenderer::findBinary('ffmpeg')`.

- [ ] **Step 1: Failing tests** (skip with `markTestSkipped` when ffmpeg isn't found):
  - `test_renders_png_frame_within_long_side`.
  - `test_short_video_takes_frame_at_ten_percent` (assert the computed seek, via a small public static `seekFor(?float $duration): float`: `seekFor(2.0) === 0.2`, `seekFor(30.0) === 1.0`, `seekFor(null) === 1.0`).
  - `test_unreadable_video_gives_no_preview` — `broken.mp4`: `render()` null; uploading it through `MediaLibrary` succeeds with `preview === null`.
  - `test_missing_ffmpeg_gives_null` (configured path `/nonexistent/ffmpeg`).
  - Library: uploading `clip.mp4` sets `preview`; `thumbnailUrl()` is the preview's variant; with an image poster set, `thumbnailUrl()` is the poster's.
  - `ImageTagTest::test_video_renders_frame_image` and `test_video_poster_wins`.
  - `PreviewsCommandTest::test_makes_video_previews`.
- [ ] **Step 2:** Run — FAIL. **Step 3:** Implement. **Step 4:** `vendor/bin/phpunit --testsuite Media` — PASS.
- [ ] **Step 5:** Commit `feat(media): video previews from one frame with ffmpeg`.

### Task 6: Folio — bin, revisions as usages, unused filter

**Files:**
- Modify: `packages/folio/src/Media/FolioRecordReferences.php` (revisions: `ShadowTypes::revisions($def)` rows whose `data` contains the id (LIKE), decoded and checked with `AssetReferences::collect()`; entry note `earlier revision`, `blocksDelete: false`; one entry per record)
- Modify: `packages/folio/src/Http/AssetLibraryController.php` (delete → bin with a "moved to the bin" notice; bin list; restore; unused filter `?unused=1` through `AssetCleanup::candidates()`, shown only when cleanup is enabled)
- Modify: `packages/folio/src/Routing/FolioRoutes.php` (`GET {prefix}/assets/bin`, `POST {prefix}/assets/bin/{id}/restore`, permission `assets.delete`)
- Create: `packages/folio/templates/admin/assets/bin.twig`; modify `index.twig`/`_tabs.twig` (Bin link, unused filter), `edit.twig` (delete confirm text)
- Modify: `packages/folio/lang/{en,de,fr}/folio.php` (new keys)
- Test: `packages/folio/tests/Media/FolioRecordReferencesTest.php`, `packages/folio/tests/Http/AssetBinTest.php`

**Interfaces:**
- Consumes: Task 3 (`delete`, `binned`, `restoreFromBin`, `AssetUsageEntry::$blocksDelete`), Task 4 (`AssetCleanup`).

- [ ] **Step 1: Failing tests:**
  - `test_revision_reference_is_reported_non_blocking` — publish a record with asset A, publish again without it: `usagesOf(A)` has one entry, note `earlier revision`, `blocksDelete === false`.
  - `test_working_reference_still_blocks`.
  - `test_revision_reference_on_sql` in `FolioRecordReferencesSqlTest` (the LIKE path on `data`).
  - `AssetBinTest`: delete from the library bins and redirects with the notice; bin page lists it for a user with `assets.delete`, 403 without; restore puts it back; unused filter hidden when cleanup is off and listing candidates when on; without `bin_dir` the library shows a one-line warning (`Deleted files are removed for good: set bin_dir in config/media.php.`) and the delete confirm says so.
- [ ] **Step 2:** Run `vendor/bin/phpunit packages/folio/tests/Media packages/folio/tests/Http/AssetBinTest.php` — FAIL. **Step 3:** Implement. **Step 4:** `vendor/bin/phpunit --testsuite Folio` — PASS (plus `TemplatesExistTest`, translations tests).
- [ ] **Step 5:** Commit `feat(folio): asset bin, revisions count as usages, unused filter`.

### Task 7: Folio — stacks, copies panel, make a copy, remake

**Files:**
- Modify: `packages/folio/src/Http/AssetLibraryController.php` (index/picker pass `copyCounts()`; edit screen gets `copies()`, original, `canRemake`, `isStale`; actions `makeCopy`, `remake`)
- Modify: `packages/folio/src/Routing/FolioRoutes.php` (`POST {prefix}/assets/{id}/copy` → redirect to the copy's editor; `POST {prefix}/assets/{id}/remake`; permission `assets.edit`)
- Modify: `packages/folio/templates/admin/assets/_grid.twig`, `_list.twig`, `_pick.twig` (stack bubble with count; in the picker a stack opens its copies to pick), `edit.twig` (Copies panel; on a copy: link to original, "Remake from original" when `canRemake`, "original changed" mark when stale), the image editor template (button "Make a copy and edit it", images only)
- Modify: `packages/folio/lang/{en,de,fr}/folio.php`
- Test: `packages/folio/tests/Http/AssetCopiesTest.php`

**Interfaces:**
- Consumes: Task 1 (`makeCopy`, `copies`, `copyCounts`, `query(..., copies:)`), Task 2 (`remake`, `canRemake`, `isStale`).

- [ ] **Step 1: Failing tests** in `AssetCopiesTest`:
  - `test_library_shows_stack_with_count` — original + 2 copies: one tile, bubble `2`; copies absent from the grid.
  - `test_search_shows_copy_tiles`.
  - `test_copies_panel_lists_copies_with_original_changed_mark`.
  - `test_make_copy_redirects_to_copy_editor` — the original unchanged.
  - `test_remake_button_only_with_known_maker`; `test_remake_post_keeps_id`.
  - `test_picker_stack_offers_original_and_copies`.
- [ ] **Step 2:** Run — FAIL. **Step 3:** Implement, following the existing library markup and Folio's htmx patterns. **Step 4:** `vendor/bin/phpunit --testsuite Folio` — PASS.
- [ ] **Step 5:** Check it in the browser on a local site (library stack, copies panel, make a copy, remake, bin, restore). **Step 6:** Commit `feat(folio): asset stacks, copies panel, make a copy, remake`.

### Task 8: Docs, constraints, release checklist

**Files:**
- Modify: `packages/media/README.md` (copies and makers; remake; bin and `bin_dir`; cleanup with a **warning box**: "Cleanup only knows the usages reported through usage sources. Folio reports its records and their revisions. An app that stores asset ids or URLs anywhere else must register an `AssetReferenceSource`, or cleanup treats those assets as unused."; video previews and ffmpeg; `importFile(…, private: true)`)
- Modify: `packages/media/composer.json`, `packages/folio/composer.json` (constraints per Global Constraints), both `ComposerConstraintsTest`s
- Modify: `ROADMAP.md` (step 4 done in 0.43; a later item: external video embeds as their own field type, if a project needs one)
- Create: `docs/superpowers/plans/2026-10-28-release-0.43-checklist.md` (release routine; site upgrades: smeyer.me and smart-stack.works set `bin_dir` to a shared deploy dir (`storage/media-bin`, added to `shared_dirs` in `deploy.php`), smeyer.me runs `preflow media:previews` after ffmpeg is present on the server (owner's go-ahead for placing a static ffmpeg); holz-objekte notes: set `derived_from` from `image_sources`, register a `watermark` maker, `rewatermark()` can become `remake()`, its `AssetSweep` can move to `media:cleanup` once its references are reported)
- Test: full suite `vendor/bin/phpunit` — PASS

- [ ] **Step 1:** Write the docs and checklist. **Step 2:** Bump constraints, run both `ComposerConstraintsTest`s. **Step 3:** Run `vendor/bin/phpunit` — all PASS. **Step 4:** Commit `docs(media): copies, bin, cleanup, video previews; 0.43 checklist`.
