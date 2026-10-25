# Media Package, Release 2 — Design

**Date:** 2026-10-25
**Status:** Approved by the owner 2026-10-25.
**Context:** Phase 2, step 5 of the BG Genius on Folio plan
(`bggenius-preflow/docs/superpowers/specs/2026-10-24-bggenius-on-folio-design.md`).
It builds on release 1 (`2026-10-25-media-package-design.md`, v0.34). Current version: v0.34.1.

## Why

Release 1 moved Folio's asset library into `preflow/media`. Release 2 adds what BG Genius
needs, plus what the owner wants from a professional media tool.

- **Replacing an image everywhere at once** is the daily pain point. The owner, in their own
  words: "change an image in the library with a new image and all places where that image is
  used should then show the new version", with old versions kept so they can go back. "As pro
  as possible" (owner).
- **Formats (presets)** with fixed boxes, cropped around the focal point. BG Genius has
  `cover-thumb` 200×200, `cover-card` 400×300, `cover-hero` 1200×400, `step-side` w500,
  `step-full` w1000 and `glossary` 100×100. Behind them sits an optional crop per image, plus
  a crop per format where art direction is needed.
- **Print-true colours:** CMYK print PDFs and images must look on screen as they would
  printed. Today GD drops colour profiles.
- **PDF previews:** a thumbnail of a PDF's first page, in the admin and through a helper on the
  public site.
- **Colours of an image:** the dominant colour and a palette, as Crelish had them. The
  spielegesellschaft site used them for each game's accent colour in gradients, glows,
  buttons and tinted backgrounds, a palette-driven hero glow, and cards coloured per game.
  The owner knows no other CMS that offers this.
- **BG Genius's own asset fields** (usage type, link to a game) are app-specific. The package
  only lets a site add fields to assets (owner).

**Standing rule (owner):** every capability lives in the media core behind public interfaces;
Folio only adds UI.

## Releases

One design, built and released in two steps (owner):

- **0.35, the file and its properties:** replace and versions, extra asset fields, colour
  management, PDF previews, colours.
- **0.36, how it's delivered:** formats with fixed boxes, crop, rotate and flip, per-format
  crops, and Folio's crop dialog.

Each release leaves the sites working and is useful on its own.

## Goals and non-goals

**Goals:** everything listed under Why, in the core with a Folio UI, working on the hosting
we have. That hosting has ImageMagick 7.1.2 with lcms (Imagick in PHP), Ghostscript 9.27 and
Poppler (`pdftoppm`, `pdftocairo`), and the owner's Mac has the same.

**Non-goals:**
- **Old BG Genius URLs** (`/img/{preset}/…`, `/uploads/games/…`): the Phase 3 migration
  rewrites the 629 steps once (owner).
- **Video thumbnails from the video itself:** posters stay hand-picked.
- **Free-angle rotation:** 90° steps plus mirroring (my call).
- **A DAM-style approval workflow.**
- **Remote storage** (S3).

**Anti-goals:**
- An edit that silently loses the original or an earlier version.
- A replaced image that a browser or CDN keeps showing old.
- Colours that differ between the admin preview and the site.
- Anything that only works through Folio's screens.

## Design — 0.35

### Replace and versions

- **Core API on `MediaLibrary`:**
  - `replace(string $id, UploadedFileInterface|string $file, ?string $by): Asset`
  - `versions(string $id): list<AssetVersion>`
  - `restore(string $id, int $version, ?string $by): Asset`
  - `deleteVersion(string $id, int $version): void`
- **Replace:**
  - The asset keeps its id, so every record that uses it shows the new file immediately.
  - The alt texts, title, focal point, crops, rotation and flip, extra fields, usage, private
    flag and a hand-picked poster all stay. The owner wants "everything to stay"; the user changes what no longer fits.
  - Only the file and what derives from it change: size, dimensions, mime, original name,
    preview, the extracted colours and the variants.
  - A replace keeps the asset's kind (image stays image, PDF stays PDF), so presets and
    fields stay meaningful. Any other kind is refused with an `AssetUploadException`.
  - It runs the same checks as an upload (type, size, pixels).
- **Versions:**
  - The previous file moves to the versions directory, with a snapshot of its file
    properties and the crops and focal point it had: `AssetVersion(int $number,
    string $path, string $mime, int $size, ?int $width, ?int $height, array $meta,
    string $createdAt, ?string $createdBy)`.
  - `restore()` is itself a replace from that version's file, so the current file also
    becomes a version and nothing is ever lost. The meta snapshot (crops, focal) is restored
    too, and the alt texts and title stay current.
  - All versions are kept. Single versions can be deleted, and `media:versions:prune
    [--keep=N] [--older-than=DAYS]` cleans up (my call).
  - Version files live in `versions_dir` (default `storage/media-versions/{id}/v{n}.{ext}`),
    never under `public/`, and are served only through Folio's admin, gated like private
    files (`PrivateFileAccess`).
  - Their variants are deleted and made again on restore.
  - **Deployment:** like `private_dir`, `versions_dir` must be a shared dir (Deployer
    `shared_dirs`). The README and release notes say so loudly, and boot refuses a
    `versions_dir` under `public/`.
- **URLs change with each file:** the stored path gains the version number
  (`public/media/Y/m/{id}-v3.{ext}`, variants `{id}-v3-480.webp`; version 1 keeps today's
  names). Browsers and CDNs therefore never show the old image. The old public files and
  their variants are removed after a successful replace. An external hotlink to the old file
  breaks, which is accepted.
- **Usage:** unchanged (same id). Delete of the asset removes all its versions.

### Extra asset fields

- A site adds fields to assets in `config/media.php`:

  ```php
  'fields' => [
      'usage_type' => ['type' => 'select', 'label' => 'Usage', 'options' => ['cover' => 'Cover', 'step' => 'Step', 'glossary' => 'Glossary', 'other' => 'Other']],
      'game' => ['type' => 'relation', 'label' => 'Game', 'relation' => ['to' => 'game']],
  ],
  'filters' => ['usage_type', 'game'],
  ```

  The field definitions use the same shape as model fields.
- **Core:**
  - The fields become part of the asset type definition, so the table step adds their
    columns and they are stored with the asset.
  - `update()` accepts them.
  - `MediaLibrary::query()` (new) lets any UI filter by them.
  - Field names may not shadow the asset's own keys, which is a boot error.
- **Folio:** renders them in the details panel with its field types (select, relation, …),
  and offers `filters` in the library toolbar next to kind and search.
- **BG Genius** defines `usage_type` and `game` this way in Phase 3. Its game relation
  becomes a usage source like Folio's records, if it wants delete protection from it.

### Colour management

- Every image the package writes (variants, PDF previews, later format crops) is converted to
  sRGB using the source's embedded ICC profile, and then tagged as sRGB. That covers CMYK
  JPEG/TIFF, Adobe RGB, Display P3 and greyscale with a profile.
- **A CMYK source without a profile** is converted from a shipped default CMYK profile. The
  builder picks a freely redistributable one (for example the ECI/FOGRA profiles or
  Ghostscript's default) and documents its licence. `color_profile_cmyk` in
  `config/media.php` can point at another.
- **Engine:** Imagick with lcms. The `GdBackend` cannot do this.
  - Without Imagick, an sRGB/untagged source behaves as today.
  - A CMYK or wide-gamut source keeps only the original (no variants), and the asset gets a
    note "needs Imagick for colour-accurate variants". It is never shown with wrong colours
    (my call).
- **Originals are never touched:** they are delivered as uploaded.

### PDF previews

- On upload and on replace, a PDF's first page is rendered to an sRGB preview image.
- **Rendering:**
  - The renderer is Poppler `pdftocairo` or Ghostscript, whichever renders CMYK through the
    PDF's output intent or a CMYK profile most faithfully. The builder decides with a test
    that compares a CMYK reference PDF's rendered patch colours against known sRGB values
    within a tolerance.
  - Long side about 1600 px, capped at `max_pixels`.
  - It runs with a time limit, and only on the asset's own file path, never on a
    user-supplied argument.
- **Behind an interface:** `Preflow\Media\Preview\PreviewRenderer` (`supports(string $mime):
  bool`, `render(string $file): ?string`) has a `PdfPreviewRenderer`. A site could add one
  for other types later.
- **Storage:** `preview` on the asset (path, width, height). The preview sits next to the file
  (public) or in `private_dir` (private) and follows the asset's privacy.
- **Templates:**
  - `media_image(pdfAsset, preset, options)` renders the preview like an image, with variants
    and srcset.
  - `media_url(pdfAsset)` stays the PDF.
  - `media_preview_url(asset)` gives the preview's URL directly.
  - Folio uses the preview as the thumbnail in the library and picker.
- **Private PDFs** have private previews. A public cover for a private PDF is chosen by
  hand, as the `poster` field does for videos, which extends to PDFs (my call).
- **Failure** (no renderer, broken PDF, time limit) leaves the asset without a preview: the
  library shows the PDF icon as today, and the upload still succeeds.

### Colours

- From the sRGB preview of images and PDFs, after colour management, the core extracts:
  - `color`: the dominant colour as hex;
  - `palette`: 5–8 hex colours, most significant first;
  - `text_color`: `#ffffff` or `#000000`, whichever reads better on the dominant colour
    (see Settled details).
- This runs on upload, replace and restore (and in 0.36 on crop). `media:colors [--all]`
  fills existing assets.
- **Extraction:**
  - Imagick quantisation on a downscaled copy, with a GD fallback (a simple histogram
    clustering) so sites without Imagick still get colours.
  - The library `ksubileau/color-thief-php` (MIT), which Crelish used, is acceptable if the
    builder prefers it. My call: the builder decides by result quality on test images, not by
    convenience.
- **Templates (CSP-safe, as the focal point):**
  - `media_colors(asset)` returns `{color, rgb: 'r,g,b', palette: [...], palette_rgb: [...], text}`
    for any markup.
  - `media_color_class(asset)` returns a class (`media-c-{hash}`) and adds one rule with the
    page's nonce to the head: `.media-c-{hash} { --media-color: #…; --media-color-rgb: r,g,b; --media-text: #…; --media-palette-1: …; … }`.
    Sites then write `rgba(var(--media-color-rgb), .8)`, exactly as spielegesellschaft's
    `--color-accent` did, without inline styles.
  - The option `media_image(…, {placeholder: true})` puts the dominant colour behind the
    image while it loads, through the same class.
  - `media_colors()` data can also be printed as `data-media-palette` JSON for scripts, as
    spielegesellschaft's game cards did.
- **Folio:** swatches for the dominant colour and the palette in the details panel, with a
  click to copy hex or RGB.

## Design — 0.36

### Formats (presets with boxes)

- `config/image.php` presets gain `height` and `fit`:

  ```php
  'cover-thumb' => ['width' => 200, 'height' => 200, 'fit' => 'cover'],
  'cover-hero'  => ['width' => 1200, 'height' => 400, 'fit' => 'cover', 'sizes' => '100vw'],
  'step-side'   => ['width' => 500, 'fit' => 'max'],
  ```

  - `cover` fills the box, cropped around the focal point.
  - `contain` fits inside the box.
  - `max` is today's width-only behaviour and stays the default for presets without a box.
- **Variants:**
  - A box preset renders at 1× and 2× of its box (or its `widths` at the box's aspect ratio),
    in the same formats as today (AVIF/WebP), made on upload or on first request with today's
    allow-list rules.
  - The file name carries the preset and a short render key from the version, rotation,
    crop, per-format crop and focal point: `{id}-v3-cover-thumb-{key}-400.webp`. Any change to
    those makes new URLs, and the old files are removed.
- `media_image(asset, 'cover-thumb')` emits `width`/`height` of the box, so there is no
  layout shift.

### Crop, rotate and flip (the original is kept)

- **Core API:**
  - `setCrop(string $id, ?CropRect $rect)`
  - `setFormatCrop(string $id, string $preset, ?CropRect $rect)`
  - `rotate(string $id, int $quarterTurns)`
  - `flip(string $id, bool $horizontal)`
- **`CropRect`** is in percent of the rotated original (x, y, width, height), so it survives
  a replace with a different pixel size (owner: keep everything). Values are clamped to the
  image.
- **Layers** (owner: simple default, opt-in art direction), applied in this order:
  1. the focal point, which already exists and is used when nothing else is set;
  2. the image crop, which cuts the original for every format;
  3. a per-format crop, which overrides the box crop for that one preset.
- **Variants** derive from original → rotation/flip → image crop → format crop or focal-point
  box. Nothing is written over the original.
- The colours are recomputed on the cropped image.
- A version snapshot includes rotation and crops.

### Folio: the crop dialog

- In the details panel, an **Edit image** dialog:
  - rotate left/right and mirror;
  - an image crop with free or fixed ratio;
  - a list of box formats, each showing its current cut, with **Adjust** to set a per-format
    crop and **Reset** to go back to the focal point;
  - the focal point click as today.
- A vendored cropper library is loaded through the screen-assets mechanism, under the strict
  nonce CSP. BG Genius's dead Cropper.js code is not reused.
- It calls the core API and shows a live preview of each format.

## Folio summary (adapter only)

- **Details panel:**
  - Replace (file input) and the version list with Restore and Delete;
  - extra fields;
  - swatches;
  - the PDF preview as the thumbnail;
  - 0.36: the Edit image dialog.
- **Library:** filters for the extra fields.
- **Thumbnails:** library and picker use previews.

All actions call `MediaLibrary`. Permissions are today's `assets.*`.

## Settled details (from the builder check, my calls)

- **What the page shows after an edit:**
  - Once rotation, flip or an image crop is set, the asset's *rendered master* is the edited
    image: the original with rotation, flip and the image crop applied, at full size, in
    sRGB. `media_url()` and a preset-less `media_image()` deliver it (and its variants).
  - The untouched original stays available as `media_original_url()` and as a download in
    the admin.
  - The asset reports the rendered master's width and height; the original's are kept
    separately.
  - Assets without edits keep today's files, URLs and output.
- **Box presets on first request:**
  - The endpoint accepts a file name only when the preset exists, the width is in that
    preset's allow-list (its `widths` at the box ratio, else 1× and 2× of the box), the format
    is supported, and the render key equals the key recomputed from the asset's current
    state.
  - Anything else is a 404 that writes nothing, as today. Old keys are therefore never
    regenerated, and requests can't make arbitrary files.
- **Storage:**
  - The new asset data are columns of the asset type, added by the existing table step
    (`RecordTableSync` adds missing columns; nothing is altered):
    - `version` is an int, empty meaning 1;
    - `versions`, `crops`, `preview` and `palette` are JSON;
    - `color` and `text_color` are strings;
    - `rotation` and `flip` hold the edit state;
    - the original's own width and height are kept separately.
  - Existing assets need no migration. They read as version 1, unedited, and without colours
    until `media:colors` runs.
- **Replace when the new file looks different:** focal point and crops (in percent) stay even
  if the aspect ratio changes completely, and so do rotation and flip (owner: everything
  stays, the user fixes what no longer fits). The preview and colours are made again from
  the new file.
- **Colour accuracy:**
  - Conversion uses relative colorimetric intent with black-point compensation.
  - Test references are computed once with ImageMagick/lcms from known CMYK and Adobe RGB
    patch values and committed as fixtures.
  - Output files are converted to sRGB and stripped. No profile is embedded, because
    untagged means sRGB in browsers and it saves bytes on every variant.
- **Which engine runs:** today's backend choice stays. GD is preferred when it can write WebP.
  Imagick is used only for sources that need colour management (an embedded non-sRGB
  profile, or CMYK) and for 0.36's edits and box crops where the GD path cannot do them.
  Existing sRGB and untagged images therefore produce the same files as today.
- **Version files are served by the core:**
  - The core serves them on a route in `MediaRoutes` (`{private_url}/{id}/v/{n}`), gated by
    `PrivateFileAccess`, so a site without Folio can expose them too.
  - Folio only links to it. The same gate applies to previews of private assets.
- **`MediaLibrary::query()`:**
  - `query(?string $kind = null, string $search = '', array $filters = [], int $page = 1,
    int $perPage = 50): AssetPage` (items plus total).
  - Filters compare by equality on the declared `filters` fields. A relation filter matches
    assets whose field contains the given id.
  - Folio's library uses it instead of loading `all()`.
  - Extra relation fields point from an asset to other records, so they are not usage of
    the asset and add no delete protection.
- **Text on the dominant colour:** instead of a `dark` flag, the core stores `text_color`.
  That is `#ffffff` or `#000000`, whichever has the higher WCAG contrast on the dominant
  colour. `media_colors()` returns it, and the class adds `--media-text`.
- **Versions housekeeping:**
  - Version numbers only grow and are never reused.
  - `prune --keep=N` keeps the N newest *earlier* versions and never touches the current
    file.
  - `delete()` and `discard()` remove all of an asset's versions. Assets have no privacy
    toggle, so version files never move between public and private.

## Tests (for the builder)

- **Replace:**
  - id, alt texts, focal point and usage are kept;
  - new URL;
  - old public files are gone;
  - the old file is in `versions_dir`;
  - a kind change is refused;
  - a private asset's versions are private.
- **Restore:** brings back file and meta, and the current file becomes a version.
- **Prune:** keeps N.
- **Version files:** never reachable under `/media`.
- **Colour:**
  - a CMYK JPEG with a profile, a CMYK JPEG without one, and an Adobe RGB image all give
    sRGB variants whose sample patches match reference values within ΔE < 3;
  - without Imagick, wide-gamut sources get no variants and a note.
- **PDF:**
  - a CMYK print PDF's preview matches reference patch colours;
  - a broken PDF still uploads, with no preview;
  - a private PDF's preview is private;
  - `media_image(pdf)` renders a picture.
- **Colours:**
  - dominant colour and palette on a known test image;
  - `text_color` on a light and a dark test image;
  - `media_color_class` adds one nonce'd rule per page, deduplicated;
  - no inline style anywhere (CSP).
- **Extra fields:**
  - stored and filtered;
  - shadowing an own key is a boot error;
  - Folio renders select and relation.
- **0.36:**
  - box sizes;
  - the focal crop goes to the right region;
  - the image crop affects all formats;
  - a format crop affects only its preset;
  - rotation;
  - new URLs after each change;
  - the variant endpoint still writes nothing for disallowed requests.
- **Byte-identical HTML** for assets without the new features: release 1's fixture stays
  green.

## Calls made here (my calls, for the owner to check)

- Versions are all kept, with manual delete and a prune command; version files outside
  `public/`, as a shared deploy dir.
- Each file version gets a new URL. Old public files are removed, and external hotlinks to
  them break.
- A replace must keep the asset's kind.
- Without Imagick, wide-gamut and CMYK sources get no variants rather than wrong colours.
- A private PDF's preview is private; a public cover for it uses the `poster` field.
- Colours are delivered CSP-safe through a class plus a nonce'd rule, with CSS variables named
  `--media-color`, `--media-color-rgb` and `--media-palette-N`.
- Rotation in 90° steps plus mirroring only.
- Crop rectangles are stored in percent, so they survive a replace.
- Colour extraction: Imagick quantisation with a GD fallback, or color-thief-php, whichever
  gives better results in tests.

## Size

Large. Two plans: 0.35 (about 6 tasks) and 0.36 (about 5 tasks), each with its own release
checklist. Sites are upgraded after each release: smeyer.me and smart-stack.works, plus
`versions_dir` as a shared dir in their deploy configs.
