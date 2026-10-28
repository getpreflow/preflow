# Media: Copies, Cleanup, Video Previews — Design

**Date:** 2026-10-28
**Status:** Draft for the owner's review.
**Context:** step 4 of the BG Genius framework order in `ROADMAP.md` ("Media for the migration"), Phase 2 of
`bggenius-preflow/docs/superpowers/specs/2026-10-24-bggenius-on-folio-design.md`. Builds on the media package as of
v0.42.0 (non-destructive crop/rotate/flip, replace and versions, `AssetReferenceSource` usage sources,
`PreviewRenderer` for PDFs, `media:previews`).

## Why

- **Copies look like duplicate uploads.** A copy made from an image (holz-objekte's watermarked public image, a
  cropped or rotated version) shows up as one more image in the library, so it looks as if the same image was
  uploaded several times (owner).
- **Redoing a copy is awkward.** The owner's case: "recreate the rotated version, and everywhere it is used the
  new one should be used". Today holz-objekte makes a brand-new asset on every copyright change, swaps the
  references itself and leaves the old one to its own sweep.
- **Unused files pile up.** A record's delete in Folio never deletes its assets; holz-objekte wrote its own sweep.
- **Videos show a big "mp4" placeholder** in the library and picker (smeyer.me uploads MP4s directly). A frame of
  the video is friendlier (owner).

## Standing rules

- The framework holds abstractions, interfaces, hooks and generic mechanisms only; project features stay in
  projects (owner, 2026-10-26).
- The media package is a core without Folio; its official admin UI lives in Folio, which is only the UI adapter.
- Safest approach wins where data can be lost (owner, step 3).

## Goals, non-goals, anti-goals

**Goals:**
1. A copy knows its original (`derived_from`); the Folio library shows an original and its copies as **one stack**.
2. A copy can be **remade from its original in place**: same asset id, so every place that uses it shows the new
   file without any reference changes.
3. Folio's image editor can **save an edit as a copy**.
4. **Cleanup of unused assets of every kind**, opt-in only, listing before deleting, deleting into a **bin** for 30 days.
5. `importFile(…, private: true)`.
6. **Video previews** from one frame via ffmpeg, so library, picker, `media_image()`, colours and box formats work
   for videos.

**Non-goals:**
- External video embeds (YouTube, Vimeo). They need different handling; a separate field type later, if a project
  needs one (owner). Noted in the ROADMAP.
- Automatic cleanup (cron, on publish, on record delete). Cleanup only runs when someone starts it.
- Releasing a publish's dropped assets on publish (the roadmap's alternative; cleanup covers it).
- holz-objekte's own migration to these pieces (its session; the upgrade notes describe how).

**Anti-goals:**
- **Cleanup deleting a file that is still used.** The framework only knows the usages reported to it through usage
  sources; an app that stores references elsewhere and reports none would lose files. Hence: opt-in, a listing
  first, a bin, and documentation that says so plainly (owner).
- Copies piling up as separate tiles again (a remake that makes a new asset).
- A site without ffmpeg failing on video uploads.

## Design

### 1. Copies (`derived_from`)

- An asset gets a `derived_from` field: the id of its original, or empty. Set by whoever creates the copy, never
  guessed from file contents.
- `MediaLibrary::derive(string $originalId, string $file, ?string $by, ?string $maker = null, array $params = [], bool $private = false): Asset`
  stores a file as a copy of the original. `maker` names how the copy was made (below); `params` are that maker's
  settings (e.g. the copyright line), stored on the copy.
- `MediaLibrary::copies(string $originalId): list<Asset>` (newest first) and `Asset::$derivedFrom`.
- One level only: a copy of a copy points at the first original (my call; a stack is one original plus its
  copies). Copying a copy makes a **sibling**: same original, same maker and params, the copied copy's edits as its
  starting point, so a watermarked copy's copy stays watermarked (my call).
- Copies are full assets: own alt text, title, private flag, crops, versions, usages.
- An original that is deleted while it has copies: refused, like an asset in use, listing its copies (my call;
  the owner deletes the copies first, or cleanup handles them).
- Existing data: no copies until an app sets the link. holz-objekte can set it from its `image_sources` map.

### 2. Remaking a copy from its original

- **Makers:** `DerivedMaker` interface in the core: `name(): string` and
  `make(string $originalFile, array $params): string` (path of the new file). Registered by name in a
  `DerivedMakers` registry; an app registers its own (holz-objekte: `watermark`).
- `MediaLibrary::remake(string $copyId, ?string $by, ?array $params = null): Asset` runs the copy's maker on the
  original's current file (with new params if given, which are then stored) and puts the result in through the
  existing `replace()` path: **same id**, the earlier file kept as a version, derived files made again. So the
  owner's case "redo exactly this version and everywhere it is used use the new one" is one call.
- **What a maker gets (my call):** the original as people see it, i.e. turned and mirrored per its settings
  (its `orientedUrl` image, without the crop). The copy then has its own crop, rotation and flip on top.
- The built-in maker `copy` (copies made in Folio) copies the original's file unchanged and starts the copy with
  the original's current crop, rotation and flip as its own settings; from then on they are the copy's. Remaking
  such a copy after the original was replaced brings the new original under the copy's edits.
- A copy without a maker (or one whose maker isn't registered) can't be remade; the call refuses with a clear
  message, and Folio hides the button.
- No automatic remake when an original is replaced (my call): the app decides (it can call `remake()` for each of
  `copies()`), and Folio shows "original changed after this copy" when the original's file is newer than the
  copy's.

### 3. Folio

- **Library stacks:** an original with copies shows as one tile: the original as thumbnail, a bubble with the
  number of copies. Copies don't show as their own tiles in the library list or the picker; the condition sits in
  `MediaLibrary::query()` so totals and pages stay right. A **text search** shows matching copies as their own
  tiles, so a copy can be found and picked directly; kind tabs, the private filter and config filters keep stacks
  (my call). In the picker, a stack opens to pick the original or one of its copies.
- **Opening a stack:** the original's edit screen gains a "Copies" panel: a small library of its copies (thumbnail,
  name, maker, used where, "original changed" mark), each opening its own edit screen. A copy's edit screen links
  back to its original and offers **Remake from original** where a maker is known.
- **Make a copy:** the image editor saves each crop, rotate and flip at once (no save step), so the button is
  **"Make a copy and edit it"**: it creates the copy (maker `copy`, or a sibling when used on a copy) and opens the
  editor on it; the original stays as it is (my call; the owner agreed to "save as copy" in the playback, this is
  the form that fits the editor).
- **Unused filter:** the library gets a filter "unused" showing what cleanup would list, with nothing deleted from
  the UI (my call).
- **Bin:** a bin view (staff with `assets.delete`) listing binned assets (manual deletes and cleanup runs) with restore. No cleanup button in Folio.

### 4. Cleanup of unused assets

- **Every kind of asset (owner):** cleanup and the bin cover images, videos, documents and any other file alike, public and private. (`derive()` is likewise kind-neutral; only Folio's "Make a copy and edit it" is image-only, since the editor is.)
- **Opt-in:** `config/media.php` `'cleanup' => ['enabled' => false, 'min_age_days' => 7, 'bin_days' => 30]`.
  Off by default; the command refuses with a pointer to the docs while it's off.
- **What counts as unused:** no usage source reports the asset, it is not a poster (the core's poster source), it is
  not the original of a copy that is not in the bin, and it is older than `min_age_days` (a fresh upload not yet
  attached is left alone).
- **Folio revisions count as usages (my call, safest):** Folio's usage source also reports assets referenced only by
  a kept revision (up to 20 per record), so restoring a revision never points at a binned file. This makes a cleanup
  run slower; that is accepted.
- **Copies and the bin (my calls):** a binned copy doesn't block binning its original. Restoring a copy whose
  original is in the bin restores the original too. Emptying the bin skips an original while one of its copies
  is live (it stays in the bin, listed with the reason). A copy whose original is gone for good loses its
  `derived_from` link on restore and becomes an ordinary asset.
- **Command:** `preflow media:cleanup` lists candidates with the reason each counts as unused and the sources that
  were asked. `--delete` moves them into the bin. Filters: `--kind=`, `--by=` (uploader), `--private`/`--public`,
  `--older-than=<days>`. Chunked like `media:previews` (the live server stops long jobs).
- **Every delete goes to the bin (owner, 2026-10-28):** `MediaLibrary::delete()` (Folio's Delete button and any
  caller) bins instead of deleting for good; only `media:bin --empty` (and `discard()` for an upload made in the
  same request) delete for good. `bin_dir` is therefore required configuration; where it is missing, delete keeps
  today's behaviour and `preflow migrate` / the Folio library warn once (my call, so an un-configured site doesn't
  break). Sites set it in the upgrade.
- **Bin:** a binned asset keeps its record (marked `binned_at`) and its files move into `bin_dir` (a shared deploy
  directory outside public/, like `versions_dir`). A binned asset doesn't resolve on the
  site and doesn't show in the library or picker. `media:bin` lists, `--restore=<id>` restores, `--empty` deletes for
  good what is older than `bin_days`; Folio's bin view restores.
- **Documentation** (media README, a clear warning box): cleanup only knows the usages reported through usage
  sources; Folio reports its records; an app that stores asset ids or URLs anywhere else must register an
  `AssetReferenceSource`, or cleanup will treat those assets as unused.

### 5. Private import

- `importFile(string $absPath, string $originalName, ?string $by, bool $private = false): Asset`, the same as
  `upload()`.

### 6. Video previews

- `VideoPreviewRenderer` in the `PreviewRenderer` slot PDFs use: ffmpeg grabs one frame (at 1 s, or 10 % of the
  length for videos shorter than 10 s — my call) as the asset's preview. Library, picker, `media_image()`, colours
  and box formats then work for videos as for PDFs.
- `config/media.php` `'ffmpeg' => null` (path; else found on PATH), as `ghostscript`. A static ffmpeg build in
  the hosting account works where the host has none.
- Made on upload and on replace; `media:previews` covers existing videos (as for PDFs), chunked.
- A poster picked by hand always wins; clearing it brings the frame back.
- Without ffmpeg, or when ffmpeg fails on a file: no preview, today's tile, no error for the person uploading.

## Constraints

- PHP 8.4 in production. Breaking changes allowed in 0.x; every consumer migrated in the same release.
- New columns on the asset table (`derived_from`, `maker`, `maker_params`, `binned_at`) are added by the media
  package's own migration, typed per 0.42.
- smart-stack.works and smeyer.me upgrade and stay green; holz-objekte.org in its own session.
- ffmpeg runs with a time limit, no shell, arguments as a list (as Ghostscript today).

## Verification

- Core tests without Folio: derive, copies, remake with a fake maker (same id, earlier file a version), refuse
  remake without a maker, refuse deleting an original with copies.
- Cleanup: disabled refuses; listing explains reasons; a used copy keeps its original; min age; bin and restore;
  empty after `bin_days`.
- Video: renderer with a small MP4 fixture where ffmpeg is installed (skipped otherwise); a missing ffmpeg leaves
  the tile.
- Folio: stack tile with count, copies panel, save as copy, remake button, unused filter, bin restore.
- smeyer.me: its uploaded videos get previews after `media:previews` (locally, then on the server once ffmpeg is
  there).

## Left to the builder

- Where `derive()` copies the file (store path) and how binned files are laid out in `bin_dir`.
- The exact Folio markup of the stack tile and copies panel, following the existing library.
- How "original changed after this copy" is measured (file version or timestamp).
- Whether a static ffmpeg for the live server is placed by a deploy step or by hand (needs the owner's go-ahead
  either way).

## Size

Large: one plan of about 6 tasks (copies and makers; remake; cleanup and bin; video renderer and private import;
Folio stacks, editor and bin view; docs, consumers, release). One release.
