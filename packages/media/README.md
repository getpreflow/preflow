# preflow/media

The asset library for Preflow: uploads, responsive image variants (WebP/AVIF), replacing a
file with versions, extra fields, colour-managed variants, PDF previews, image colours,
private files, and where each asset is used. The package does all of it by itself. Folio is only a UI
adapter on top (library screen, asset field); it adds screens and binds its own rules to the
hooks below.

Requires `preflow/core`, `preflow/data`, `preflow/view`, `preflow/routing` and `nyholm/psr7`;
suggests `preflow/i18n` (alt text per language).

## Install

```
composer require preflow/media
```

Without Folio, list the provider in `config/providers.php`:

```php
Preflow\Media\MediaServiceProvider::class,
```

With Folio you add nothing: Folio registers it. Listing it as well is harmless, because a
provider class is registered once.

The provider reads the config at boot, registers the `MediaLibrary`, adds the routes for
missing variants and private files, and the template functions `media_image()`,
`media_url()`, `media_preview_url()`, `media_colors()` and `media_color_class()`. With SQL storage, `preflow migrate` creates the asset table (the
`media.table` step), so a site without Folio needs no manual SQL.

## Library

Files are written to `public/media/Y/m/{id}.{ext}` and served by the web server directly.
Metadata (alt text per language, title, focal point, video poster) are records stored in the
`folio_assets` table (the name is kept, so existing data keeps working).

```php
$library = $container->get(Preflow\Media\MediaLibrary::class);
$asset = $library->upload($uploadedFile, $userId);   // Asset, throws AssetUploadException
$library->find($id);  $library->all();  $library->update($id, ['alt' => [...]]);
$library->delete($id); // refuses a used asset: AssetInUseException lists the usage
```

- `delete()` refuses an asset that is used anywhere (see usage sources) and throws
  `Preflow\Media\Usage\AssetInUseException`.
- `find($id)`, `all()` and `query()` (below) read assets; `replace()`, `versions()`, `restore()`,
  `deleteVersion()` and `prune()` are in Replace and versions.
- **Uploads do their work at once.** `upload()` (and a replace or restore) makes the variants,
  reads the colours and, for a PDF, renders its preview before it returns, in the same request.
  An image takes as long as its encodes; a PDF can add up to 30 seconds (the render's time
  limit). There is no queue.
- `discard($id)` is intended only for an asset created in this request that nothing references
  yet, e.g. when the record that should reference it fails validation. It bypasses the usage
  check, so do not use it for anything else.

## Config

`config/media.php`:

```php
return [
    'dir' => __DIR__ . '/../public/media', // default
    'url' => '/media',
    'storage' => 'json',                  // a data driver name
    'max_bytes' => 20 * 1024 * 1024,
    'max_pixels' => 30_000_000,          // larger images get no variants
    'warm_on_upload' => false,           // true: make the step widths during the upload too
    'allow_svg' => false,
    'widths' => [480, 768, 1024, 1600],   // default: the config/image.php preset widths
    'quality' => 75,
    'private_dir' => __DIR__ . '/../storage/private-media', // default; never under public/
    'private_url' => '/files',                // default
    'versions_dir' => __DIR__ . '/../storage/media-versions', // default; never under public/
    'fields' => [],                       // extra asset fields, see Extra fields
    'filters' => [],                      // names of extra fields offered as filters
    'color_profile_cmyk' => null,         // ICC file for CMYK images without a profile; null: the shipped FOGRA39L
    'ghostscript' => null,                // gs binary for PDF previews; null: looked up on PATH
    'pdftocairo' => null,                 // Poppler's pdftocairo; null: looked up on PATH
];
```

- Without `config/media.php`, an existing `config/folio.php` `assets` block is read instead
  (deprecated, logged to the debug collector and listed in the devtools debug toolbar). The
  two are never merged: a present `media.php` wins entirely.
- Who may download private files is not a media setting; see Private files.
- A `private_dir` or `versions_dir` inside `public/` or the media directory is refused at boot,
  and so is a `color_profile_cmyk` that is not a readable file.
- `versions_dir` and `private_dir` set to `null` (or `''`) turn the feature off: without a
  `versions_dir`, `replace()` throws `AssetUploadException` rather than lose the current file.

## Images in templates

```twig
{{ media_image(record.cover, 'hero', {sizes: '(min-width: 60rem) 50vw, 100vw', class: 'cover'}) }}
<a href="{{ media_url(record.whitepaper) }}">Download</a>
```

- `media_image()` emits `<picture>` with AVIF and WebP `srcset`s and an `<img>` with `width`,
  `height`, `loading` and the alt text in the page's language. A document renders as a link.
- `media_url()` gives an asset's URL (`''` without one; the master for an edited image).
  `media_original_url()` gives the untouched original. Both accept what an asset field
  stores: a library id, a legacy upload path, or a list (its first item).
- Options and presets are those of `responsive_image()` (`config/image.php` `presets`:
  `widths`, `sizes`, `loading`, `class`, `attrs`), plus:
  - `step: 160` adds widths every 160 px between the preset's smallest and largest width
    (made on first request, see below);
  - `priority: true` for the page's key image: `fetchpriority="high"`, `loading="eager"` and
    one `<link rel="preload" as="image">` per image in the head. A layout that prints
    `{{ head() }}` before the body has already written its head, so leave the head to the
    automatic injection to keep the link.
- The focal point becomes classes (`folio-focal folio-fx-30 folio-fy-70`), never an inline
  style; their CSS is added with the CSP nonce. It shows only when your CSS crops the image
  (`object-fit: cover`). The class names are kept from Folio.

## Variants

Made on upload with GD or Imagick: every configured width the image can fill, plus its own
width when narrower than the largest. Each as WebP, and AVIF where supported. Photos are
turned upright by their EXIF orientation. Images above `max_pixels` keep only the original.
GIF and SVG are served as they are; without GD or Imagick no variants are made.

After changing widths, run:

```
php preflow media:variants [--id=<id>] [--all] [--limit=<n> [--offset=<n>]]
```

(`--all` also makes the step widths.) `--limit` makes the variants of at most n images (newest
first) and prints the command that continues, with `--offset`; see Long runs below. The command is listed in the package's
`extra.preflow.commands`; it needs `preflow/devtools`.

**Step widths on first request.** Listed `widths` are made on upload; step widths when a
browser first asks for one: the missing file reaches the front controller, the core makes it,
writes it next to the original and returns it, and from then on the web server serves it.
Only widths a preset defines, never wider than the original and in a supported format, are
made; anything else under the media URL is a 404 that writes nothing. Concurrent first
requests wait on a per-file lock. `warm_on_upload` or `media:variants --all` make them in
advance (use one on shared hosting). If an encode fails, the browser is redirected
(uncached) to the closest existing size. A page under the media URL is not routed.

## Box presets: height and fit

A preset without `height` is a width preset, as before. With `height` it gets a fixed box;
`fit` says how the image fills it:

```php
// config/image.php
'presets' => [
    'card'  => ['widths' => [400, 800], 'sizes' => '50vw'],                       // width preset (fit: max)
    'thumb' => ['width' => 400, 'height' => 300, 'fit' => 'cover'],               // cut to 4:3
    'strip' => ['width' => 1200, 'height' => 300, 'fit' => 'cover', 'widths' => [600, 1200]],
    'logo'  => ['width' => 300, 'height' => 200, 'fit' => 'contain'],             // whole image inside the box
],
```

- `fit: max` (the default) is today's behaviour: the image keeps its shape.
- `fit: cover` cuts the box's ratio out of the image, centred on the focal point (or on the
  format crop, see below). `fit: contain` keeps the whole image (or the image crop) and
  only sets the box's `width`/`height` attributes.
- A `cover` or `contain` preset needs a positive `width` and `height`. Its `widths` default to
  1x and 2x of `width`. A box file is never wider than its source region (no upscaling).
- **Names.** A box preset's name matches `^[a-z][a-z0-9-]*$`. It is not `edit`, `preview` or
  `oriented`, does not start with `edit-`, `preview-` or `oriented-`, and is not `v<digits>`
  or `v<digits>-…`; these would collide with the names of other derived files. Any other name
  is a boot error that names `config/image.php` and the preset. Presets with `fit: max` keep
  any name.
- **File names.** `{stem}-{preset}-{key}-{w}.{fmt}`, for example
  `2026/10/{id}-v3-thumb-1a2b3c4d-400.webp`; for an edited image the stem is the master's:
  `2026/10/{id}-edit-9f8e7d6c-thumb-1a2b3c4d-400.webp`. The key (8 hex characters) changes
  with the box, the crop region and the focal point, so an edit gives new URLs and old ones
  are not cached stale.
- **Made on first request**, not at upload: the missing file reaches the front controller,
  which cuts and writes it (like step widths; anything that is not one of the asset's current
  box files is a 404 that writes nothing). `warm_on_upload` and `media:variants --all`
  make them in advance. Any edit or focal-point change deletes the asset's box files.
- Box files are cut from the delivered image (the master of an edited image) and are sRGB and
  stripped. Private assets get no box variants.

## Editing images

An image can be rotated, mirrored and cropped without touching the uploaded file. The edit
is data on the asset; the library renders a **master** from the original and pages deliver
the master. Editing needs a public JPEG, PNG, WebP or AVIF image; private images, GIF and SVG
are refused (`InvalidArgumentException`).

```php
use Preflow\Media\CropRect;

$library->isEditable($asset);                       // public JPEG, PNG, WebP or AVIF
$library->rotate($id, 1);                           // quarter turns clockwise (negative: counter-clockwise)
$library->flip($id, true);                          // mirror horizontally (false: vertically)
$library->setCrop($id, CropRect::of(10, 5, 80, 60)); // x, y, width, height in percent; null removes it
$library->setFormatCrop($id, 'thumb', CropRect::of(0, 0, 50, 50)); // cut for one cover preset; null removes it
```

Each call returns the saved asset and renders the master straight away.

- **Order.** EXIF upright, then mirror, then rotate clockwise, then the image crop. The result
  is the master `{stem}-edit-{key}.{jpg|png|webp}` (AVIF originals get a WebP master), with
  its variants `{stem}-edit-{key}-{w}.{fmt}`. The original file stays as uploaded.
- **Coordinates.** A `CropRect` is in percent of the *rotated and flipped original* and is
  clamped to the image. The focal point is in percent of the *delivered image* (the master
  when edited), so `object-position` keeps its meaning. Rotating or mirroring moves the crop,
  the format crops and the focal point along; setting the crop moves the focal point with it.
  A crop of the whole image is stored as no crop.
- **Format crops** (`setFormatCrop`) art-direct one `cover` preset. They are cut from the
  master, so a format crop is **clamped to the image crop**: to show an area outside the
  image crop, remove the image crop first. Rotating clears the format crops (rotating by
  whole turns, `rotate($id, 0)` or `4`, changes nothing); mirroring mirrors them. `formatRegion($asset, $preset)` returns the region a preset cuts,
  in the same coordinates.
- **What reads what.** `media_url()` and `url()` give the master for an edited image;
  documents keep their file URL. `media_original_url()` (`$library->originalUrl()`) gives the
  untouched original (a legacy upload path gives its legacy URL). `deliveryAsset()` is the
  asset as pages see it.
- **Cropping does not hide the original.** The untouched original stays public at its own URL
  (`media_original_url()`), cropped-away areas included. Use a private asset for content that
  must not be public.
- **Colour.** The master is sRGB and stripped. A CMYK or wide-gamut edited image needs
  Imagick: without it no master is made, the asset's `note` says
  `needs Imagick for colour-accurate variants` and pages keep the original.
- **Reserved field names.** New asset fields `rotation` (0 to 3), `flip`, `crop`, `crops`
  and `master` cannot be used as extra field names. Existing assets read as unedited.
- **Replace and restore** keep the edits (the crop applies as a percentage to the new
  file); restoring a version brings back the edits it had. Restoring a version made before
  0.36 keeps the asset's present edits (such a version has none stored).
- **Custom backends.** `ImageBackend` has a new method, `writeEdited()` (renders the master:
  upright, mirror, turns, crop, longest side, JPEG/PNG/WebP), and `writeVariants()` jobs may
  carry a `crop`. A third-party backend must implement `writeEdited()`.

## Replace and versions

`replace()` swaps an asset's file and keeps everything that makes the asset itself: the id, so
every record that references it still does, plus alt text, title, focal point, extra fields,
usage, the private flag and the poster.

```php
$asset = $library->replace($id, $uploadedFile, $userId);  // or a path on disk (importers)
$library->versions($id);          // list<AssetVersion>, newest first
$library->restore($id, 2, $userId);   // make version 2 the current file again
$library->deleteVersion($id, 2);
$library->prune($id, keep: 3, olderThanDays: 90);   // returns how many were deleted
```

- The new file must be the same kind (image, video or document, `Asset::kind()`), else
  `AssetUploadException` and nothing changes. A mime change inside the kind is fine.
- Changes with the file: size, width, height, mime, original name, preview, colours and
  variants. Old public files and their variants are removed after a successful replace.
- The old file is first copied to `versions_dir` (`{versions_dir}/{id}/v{n}.{ext}`); the asset
  records it in `versions` (number, size, size in pixels, mime, original name, date, the focal
  point at that time). Version numbers only grow; a deleted number is not reused.
- **Names.** Version 1 keeps today's names (`Y/m/{id}.{ext}`, variants `{id}-{w}.{fmt}`). From
  version 2 the file is `Y/m/{id}-v{n}.{ext}` and its variants `{id}-v{n}-{w}.{fmt}`, so a
  browser or CDN never serves the old bytes under the new name. Existing assets read as
  version 1 with no earlier versions; nothing is rewritten.
- `restore()` is a replace from an earlier file, so the current file becomes a version too.
  The restored version's focal point comes back; alt texts and title stay as they are.
  Restoring keeps the restored version.
- `prune($id, $keep, $olderThanDays)` keeps the `$keep` newest earlier files and, with
  `$olderThanDays`, deletes only those replaced longer ago than that. The current file is never
  touched. Nothing is pruned automatically.
- Replaces, restores, version deletes, prunes, edits (`update()`), re-made variants, previews
  and colours, and the delete of one asset run one after another, each on the record the one
  before saved (a lock per asset in `{versions_dir}/.locks/{id}.lock`; the delete removes it).
  A `media:variants` run that read the asset before a replace never undoes it.
- **Serving.** Earlier files are never under `public/`. They are sent by the core route
  `{private_url}/{id}/v/{n}` behind `PrivateFileAccess` (see Private files), whether the asset
  is public or private. Without a bound rule that is a 403.
- **`versions_dir` must be a shared directory in a deployment** (Deployer `shared_dirs`:
  `storage/media-versions`) and git-ignored, or the next release deletes the earlier files.

```
php preflow media:versions:prune [--keep=N] [--older-than=DAYS] [--id=<id>]
```

Give `--keep`, `--older-than` or both; `--id` limits it to one asset.

## Extra fields

Add fields of your own to every asset (a licence, a photographer, a category) in
`config/media.php`:

```php
'fields' => [
    'credit'   => ['type' => 'string', 'label' => 'Credit'],
    'category' => ['type' => 'select', 'label' => 'Category', 'options' => ['team' => 'Team', 'event' => 'Event']],
    'approved' => ['type' => 'boolean', 'label' => 'Approved'],
    'author'   => ['type' => 'relation', 'relation' => ['to' => 'author'], 'label' => 'Author'],
],
'filters' => ['category', 'approved', 'author'],   // select, relation and boolean fields only
```

- A field has the shape of a model field (`type`, `label`, `options`, …). Each becomes a text
  column of the asset type; values are in `Asset::$extra` (`$asset->extra['credit']`).
- A name must be letters, digits and underscores, starting with a letter, and must not be one
  of the asset's own keys (`path`, `alt`, `title`, `color`, `palette`, `version`, `private`, …):
  that is a boot error naming the field.
- `$library->update($id, ['credit' => '…'])` sets them. A value that does not fit its field
  (an option that does not exist) is cleared; other unknown keys are ignored.
- `filters` entries that are not a declared select, relation or boolean field are ignored and
  reported to the debug collector.

### Querying

```php
$page = $library->query(kind: 'image', search: 'harbour', filters: ['category' => 'event'], page: 1, perPage: 50);
$page->items;  // list<Asset>, newest first; also ->total, ->page, ->perPage
```

`kind` is `image`, `video` or `document` (null: all). `search` matches every word in the
original name, title or alt text (in any locale). `filters` compare by equality on the
configured `filters` fields (a relation by id, a list means any of them; a multiple relation
means "contains"), and on the asset's own `private` flag. A page past the end gives the last
page. Works on JSON and SQL storage.

## Colour management

Variants are always sRGB, so every browser shows the colours the photographer saw.

- A **CMYK** image is converted to sRGB. Without an embedded profile it is taken as FOGRA39L
  (coated), a CC0 profile shipped in `packages/media/resources/icc/` with its licence;
  `color_profile_cmyk` points at another ICC file (SWOP, a newsprint profile, …).
- An image with another profile, such as **Adobe RGB** or Display P3, is converted too.
- Conversion uses relative colorimetric intent with black-point compensation, and the output
  carries no embedded profile.
- sRGB and untagged images are not touched: they use the same backend (GD when it can write
  WebP) and produce the same files as before.
- **Needs Imagick with lcms** (the common build). Without Imagick a CMYK or non-sRGB-profiled
  image gets no variants (the original is served) and the asset's `note` reads
  `needs Imagick for colour-accurate variants`; the library shows it. Installing Imagick and
  running `media:variants --all` fixes those assets.

## PDF previews

A PDF gets a preview of its first page: a PNG in sRGB, long side 1600 px (capped by
`max_pixels`), stored next to the file (`Y/m/{id}[-v{n}]-preview.png`), or in `private_dir`
for a private asset. `media_preview_url(id)` returns its URL (`''` without one); the library's thumbnails of
documents use it.

- **Needs one of two programs on the server:**
  - **Ghostscript 10.03.1 or newer** (`gs`), the colour-faithful engine: it follows a print
    PDF's CMYK output intent. Older versions have published `-dSAFER` escapes, so they are
    never run on uploaded files.
  - otherwise **Poppler's `pdftocairo`**, which runs no PostScript. It converts CMYK naively
    and ignores the output intent, so print PDFs look less true.
  - Neither: no previews; uploads still work.
- Both are looked up on PATH and in the usual places; `ghostscript` and `pdftocairo` in
  `config/media.php` name the binaries.
- Rendering uses an argument list (no shell), only the asset's own path, and a 30 second limit.
  A broken, encrypted or empty PDF leaves `preview` null and the upload succeeds.
- A private asset's preview is private too: `{private_url}/{id}/preview`, behind
  `PrivateFileAccess`.
- A replace makes the preview again.
- **Rendering takes time on upload.** It runs in the upload's request: a PDF can add up to 30
  seconds before the upload returns.
- **`media_image()` on a PDF** shows its preview (or its poster, if one is set) as a picture
  once a preview exists; before that, the asset stays a link. A private PDF's preview is the
  gated one.
- A page larger than about 8000 pt (also by `/UserUnit`) is fitted into the preview's size in
  one pass instead of being measured by drawing, so it costs no huge bitmap.

```
php preflow media:previews [--id=<id>] [--all] [--limit=<n>] [--offset=<n>]
```

Makes the previews of documents that have none; `--all` (or `--id`) makes them again, for
example after Ghostscript has been upgraded. A preview is rendered to a temp file and only
then swapped in, so a render that fails or is killed keeps the working preview. `--id` of an
unknown asset is an error (exit 1).

## Colours

Images (and the previews of documents) get their colours when they are uploaded or replaced:

| Field | Meaning |
|---|---|
| `color` | the dominant colour, `#rrggbb` |
| `palette` | 1 to 8 colours that occur in the image, most significant first (a flat image has fewer than 5) |
| `text_color` | `#ffffff` or `#000000`, whichever has the higher WCAG contrast on `color` |

SVG and videos have none. In templates:

```twig
{% set c = media_colors(record.cover) %}   {# {color, rgb: 'r,g,b', palette, palette_rgb, text} or null #}
<section class="{{ media_color_class(record.cover) }}">   {# '' without colours #}
    {{ media_image(record.cover, 'hero', {placeholder: true}) }}
</section>
```

`media_color_class()` returns a class `media-c-{hash}` whose rule sets CSS variables
`--media-color`, `--media-color-rgb`, `--media-text` and `--media-palette-1` …
`--media-palette-N`. The rule goes into one `<style>` in the head with the CSP nonce, once per
page and colour set, so there is never an inline style. Use them in your CSS:

```css
.card { background: rgba(var(--media-color-rgb), .8); color: var(--media-text); }
.card a { border-color: var(--media-palette-2, var(--media-color)); }
```

`{placeholder: true}` for `media_image()` puts the dominant colour behind the `<img>` until
it has loaded.

```
php preflow media:colors --all                 # every asset without colours
php preflow media:colors --all --limit=200     # at most 200 of them, then says how many are left
php preflow media:colors --id=<id>             # that asset again
```

Run `--all` once after upgrading, so existing assets get theirs.

## Commands

All are in the package's `extra.preflow.commands` and need `preflow/devtools`.

| Command | Does |
|---|---|
| `media:variants [--id=<id>] [--all] [--limit=N] [--offset=N]` | makes the variants again (`--all` includes step widths and box variants) |
| `media:versions:prune [--keep=N] [--older-than=DAYS] [--id=<id>]` | deletes earlier files of replaced assets |
| `media:previews [--id=<id>] [--all] [--limit=N] [--offset=N]` | makes PDF previews |
| `media:colors --all [--limit=N] [--offset=N] \| --id=<id>` | reads the colours of images and previews |

**Long runs.** A host may end long SSH sessions (some after about 12 minutes). Run
the backfills in chunks with `--limit=N`: each run does at most N assets and, when some are
left, prints the command for the next chunk.

- `media:previews` without `--all` and `media:colors --all` resume by themselves: an asset that
  got its preview or colours drops out of the list, so a stopped run is continued by running
  the same command again. When some could not be made, the printed command adds `--offset` to
  skip them.
- `media:previews --all` and `media:variants` redo every asset, so they continue with the
  printed `--offset` (the list is newest first; an upload in between shifts it by one, which
  only makes one asset twice).

```
php preflow media:colors --all --limit=300      # repeat until nothing is left
php preflow media:previews --limit=50           # repeat until nothing is left
php preflow media:variants --all --limit=200    # then: --all --limit=200 --offset=200, …
```

## Usage sources

An asset counts as used where a registered source reports it. `AssetUsage` asks every
source in registration order; `MediaLibrary::delete()` refuses while any reports a use.
There is one `AssetUsage` per container (`#[Singleton]`), whichever provider asks for it
first.

- The core registers `PosterReferences` as the last source (`register($source, last: true)`),
  so poster uses list after record uses: an asset used as another asset's poster.
- Folio registers its record source (working copies and published versions, nested blocks
  included). `Preflow\Folio\Assets\AssetReferences` stays in Folio.
- **If you store asset references, register a source**, or deleting an asset used there is
  not blocked:

```php
use Preflow\Media\Usage\{AssetReferenceSource, AssetUsage, AssetUsageEntry};

final class OrderReferences implements AssetReferenceSource
{
    public function name(): string { return 'orders'; }

    /** @return list<AssetUsageEntry> */
    public function usagesOf(string $assetId): array { /* scan or ask an index */ }
}

// in your provider's boot()
$container->get(AssetUsage::class)->register(new OrderReferences());
```

Register sources in your provider's `boot()`: every provider has registered by then, so the
order of `config/providers.php` does not matter. (A source registered in `register()` is
kept too, also before `MediaServiceProvider`.) To build the source only when an asset is
checked, wrap it: `new LazyReferenceSource('orders', fn () => $container->get(OrderReferences::class))`.

A source may scan its data on request or keep an index up to date on save; the interface
does not care. It is asked on every delete, so keep it cheap: Folio's skips types without
asset or blocks fields and, on SQL storage, reads only rows whose columns contain the id.

## Private files

Uploads with `private: true` go to `private_dir`, outside `public/`, get no variants, and
have one URL: `{private_url}/{id}` (`/files/3f…e1`). `media_url()` and `media_image()` (a
plain `<img>`, no srcset) use it.

- The core asks a `Preflow\Media\Access\PrivateFileAccess` before it serves anything. With
  none bound, it denies with a 403 (`DenyAllPrivateFileAccess`).
- Folio binds its rule: a signed-in user with a permission, from `config/folio.php`
  `assets.private_permission` (default `folio.dashboard`) and `assets.private_login`
  (optional; a redirect to `login?next=…` instead of a 403). These two stay in `folio.php`.
- A site's own `PrivateFileAccess` must be bound by a provider listed **after**
  `FolioServiceProvider` in `config/providers.php`, or Folio's binding replaces it.
- `decide(ServerRequestInterface $request, Asset $asset): ?ResponseInterface` returns `null`
  to allow, or the response to send (403, redirect). It is asked for every request on the
  route, also for an unknown id, so a refused visitor learns nothing about which ids exist.
- Earlier versions (`{private_url}/{id}/v/{n}`) and private previews (`{private_url}/{id}/preview`)
  go through the same rule. For a version the asset is passed as private and the request
  carries the attribute `PrivateFileAccess::VERSION` (the version number; null on the other
  routes), so a rule can keep earlier files to fewer people. Folio's does: earlier versions
  are staff only (`assets.view`); its members' permission never opens them.
- Files are sent with `Cache-Control: private, no-store`; images inline, everything else as
  an attachment with the original name.
- The route needs the session: the prefix of `private_url` must be covered by
  `session.paths` in `config/auth.php` and by the session cookie's path.
- `private_dir` and `versions_dir` must be shared directories in a deployment and git-ignored.

## Allowed files

The type is sniffed from the content, never from the client's name; the stored extension
follows it. Allowed: JPEG, PNG, GIF, WebP, AVIF, MP4, WebM, QuickTime, PDF, plain text, CSV,
ZIP, and Word, Excel and PowerPoint files. SVG is refused unless `allow_svg` is set.

## SQL storage

`preflow migrate` creates the table (`media.table`), as `RecordTableSync` makes any record
table: `uuid` as the primary key and every other field as a nullable text column (TEXT on
SQLite, LONGTEXT on MySQL): `path`, `original_name`, `mime`, `title`, `poster`,
`created_at`, `created_by`, `size`, `width`, `height`, `focal_x`, `focal_y`, `private`,
`duration`, `alt` and `variants` (JSON), and `version`, `versions` (JSON), `preview`, `color`,
`palette`, `text_color`, `note` and one column per extra field. Columns it adds later are text too.

## Use without Folio

List `MediaServiceProvider`, add `config/media.php` if the defaults do not fit, call
`$library->upload()` from your own controller, and render with `media_image()`. Bind a
`PrivateFileAccess` if you use private files, and register a usage source if you store
references. Folio is not needed.
