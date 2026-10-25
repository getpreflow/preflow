# preflow/media

The asset library for Preflow: uploads, responsive image variants (WebP/AVIF), private files,
and where each asset is used. The package does all of it by itself. Folio is only a UI
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
missing variants and private files, and the template functions `media_image()` and
`media_url()`. With SQL storage, `preflow migrate` creates the asset table (the `media.table`
step), so a site without Folio needs no manual SQL.

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
];
```

- Without `config/media.php`, an existing `config/folio.php` `assets` block is read instead
  (deprecated, logged to the debug collector and listed in the devtools debug toolbar). The
  two are never merged: a present `media.php` wins entirely.
- Who may download private files is not a media setting; see Private files.
- A `private_dir` inside `public/` or the media directory is refused at boot.

## Images in templates

```twig
{{ media_image(record.cover, 'hero', {sizes: '(min-width: 60rem) 50vw, 100vw', class: 'cover'}) }}
<a href="{{ media_url(record.whitepaper) }}">Download</a>
```

- `media_image()` emits `<picture>` with AVIF and WebP `srcset`s and an `<img>` with `width`,
  `height`, `loading` and the alt text in the page's language. A document renders as a link.
- `media_url()` gives an asset's URL (`''` without one). Both accept what an asset field
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
php preflow media:variants [--id=<id>] [--all]
```

(`--all` also makes the step widths.) The command is listed in the package's
`extra.preflow.commands`; it needs `preflow/devtools`.

**Step widths on first request.** Listed `widths` are made on upload; step widths when a
browser first asks for one: the missing file reaches the front controller, the core makes it,
writes it next to the original and returns it, and from then on the web server serves it.
Only widths a preset defines, never wider than the original and in a supported format, are
made; anything else under the media URL is a 404 that writes nothing. Concurrent first
requests wait on a per-file lock. `warm_on_upload` or `media:variants --all` make them in
advance (use one on shared hosting). If an encode fails, the browser is redirected
(uncached) to the closest existing size. A page under the media URL is not routed.

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
- Files are sent with `Cache-Control: private, no-store`; images inline, everything else as
  an attachment with the original name.
- The route needs the session: the prefix of `private_url` must be covered by
  `session.paths` in `config/auth.php` and by the session cookie's path.
- `private_dir` must be a shared directory in a deployment and git-ignored.

## Allowed files

The type is sniffed from the content, never from the client's name; the stored extension
follows it. Allowed: JPEG, PNG, GIF, WebP, AVIF, MP4, WebM, QuickTime, PDF, plain text, CSV,
ZIP, and Word, Excel and PowerPoint files. SVG is refused unless `allow_svg` is set.

## SQL storage

`preflow migrate` creates the table (`media.table`), as `RecordTableSync` makes any record
table: `uuid` as the primary key and every other field as a nullable text column (TEXT on
SQLite, LONGTEXT on MySQL): `path`, `original_name`, `mime`, `title`, `poster`,
`created_at`, `created_by`, `size`, `width`, `height`, `focal_x`, `focal_y`, `private`,
`duration`, `alt` and `variants` (JSON). Columns it adds later are text too.

## Use without Folio

List `MediaServiceProvider`, add `config/media.php` if the defaults do not fit, call
`$library->upload()` from your own controller, and render with `media_image()`. Bind a
`PrivateFileAccess` if you use private files, and register a usage source if you store
references. Folio is not needed.
