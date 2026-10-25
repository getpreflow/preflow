# Media Package, Release 1 (the move) — Design

**Date:** 2026-10-25
**Status:** Approved by the owner 2026-10-25.
**Context:** Phase 2, step 5 of the BG Genius on Folio plan
(`bggenius-preflow/docs/superpowers/specs/2026-10-24-bggenius-on-folio-design.md`).
Current framework version: v0.33.0.

## Why

The owner decided that asset handling becomes its own framework package, with a core and
a Folio integration, replacing Folio's built-in asset library. BG Genius's media features
(box presets with crop, non-destructive crop/rotate, replace file, extra asset fields such
as usage type and game, old URLs) are then added to that package.

The work is split into **two releases** (owner, 2026-10-25):

1. **The move (this design):** Folio's asset code moves into `preflow/media`, with no new
   features. smeyer.me and smart-stack.works switch to it. Nothing a visitor or an editor
   sees changes.
2. **The additions (its own design later):** BG Genius's features, built on the core from
   release 1.

## Rule: the core does everything, Folio only adds UI

The owner's standing rule for anything around Folio (2026-10-25): every capability lives
in the package core behind public interfaces, so anyone can build their own UI. The Folio
integration is an adapter that adds screens, fields and navigation.

For media this means:

- **In the core:** uploading, metadata updates, deleting (including the "refuse when used"
  rule), variants and their serving, presets and `<picture>` rendering, private files and
  their gated serving, usage lookup, and the commands.
- **In Folio:** the library screen, the picker drawer, the asset field type, admin
  thumbnails, navigation and permissions, and the content export/import. Folio also binds
  its sign-in and permission check into the core's private-file access hook, and registers
  its records as a usage source.

No core class imports a `Preflow\Folio\…` class. A test enforces this, the way
`ComposerConstraintsTest` guards constraints.

## Goals

- `preflow/media` (namespace `Preflow\Media`) contains everything Folio's asset library
  does today:
  - upload with type, size and pixel limits;
  - per-language alt text, title, focal point and video poster;
  - WebP/AVIF width variants (GD/Imagick, EXIF orientation), made on upload or on the
    first request with locking, plus the redirect fallback;
  - private files;
  - usage;
  - `<picture>` rendering with presets, `step`, `priority` and focal classes.
- Release 1 is invisible to visitors and editors:
  - **Storage stays the same:** the `folio_assets` table, `public/media/Y/m/{id}.{ext}`,
    `storage/private-media`, and the URLs `/media/…` and `/files/{id}`.
  - **Behaviour stays the same:** HTML output, admin screens and texts.
- **Usage becomes an interface.** Whatever stores asset references reports them. Folio
  registers its records (with published versions) as one source; a site or package can
  register its own.
- **One release of grace for unchanged sites** (holz-objekte.org is being worked on in
  another session and must keep working on 0.34 without edits):
  - the old class names are aliases of the new ones;
  - `folio_image()`/`folio_asset_url()` keep working;
  - `config/folio.php` `assets` is still read;
  - `folio:assets:variants` still runs.

  All four are deprecated (README and release notes) and removed in a later release.

Non-goals for release 1: anything new (box presets, crop/rotate, replace file, extra
fields, old-URL support); renaming the table; changing URLs; a non-Folio admin UI.

## Design

### Package layout (`packages/media`)

- `Preflow\Media\Asset`, `Variant` — moved unchanged from `Preflow\Folio\Assets`.
- `Preflow\Media\MediaLibrary` — today's `AssetLibrary`:
  - upload, import a file, find, list, update metadata, delete, variants, URLs,
    private dir, thumbnail;
  - `delete()` checks `AssetUsage` itself and throws `AssetInUseException` (with the
    usages) when the asset is used, so every UI gets the rule. Today the screen enforces
    it.
- `Preflow\Media\Image\…` — the backends (`ImageBackend`, `GdBackend`, `ImagickBackend`,
  `ImageBackends`, `Orientation`), `VariantGenerator` and `ImageWidths`, moved unchanged.
- `Preflow\Media\ImageTag` — today's `ImageTag`. Its only Folio dependency is
  `ContentLocale`, used for the current language. It is replaced by a small core
  interface, `Preflow\Media\LocaleProvider` (`current(): string`, `default(): string`),
  bound by default to the i18n `Translator` locale where present, else `config/app.php`
  `locale`. Folio binds its own `ContentLocale` to it.
- **Usage:**
  - `Preflow\Media\Usage\AssetReferenceSource` (interface):
    `name(): string`; `usagesOf(string $assetId): list<AssetUsageEntry>`.
  - `AssetUsageEntry`: readonly; `label`, `?url` (where to edit it), `source`,
    `?note` (e.g. "published version").
  - `Preflow\Media\Usage\AssetUsage` holds the registered sources:
    `register(AssetReferenceSource)`, `of(string $assetId): list<AssetUsageEntry>`, and
    `isUsed(string $assetId): bool`.
  - Folio's current `AssetUsage` logic (type catalog, shadow types, record labels) and
    `AssetReferences` (walking record data and blocks for asset ids) become
    `Preflow\Folio\Media\FolioRecordReferences implements AssetReferenceSource`.
    It is registered by the Folio provider, with the same results as today.
  - **Why an interface** (owner confirmed 2026-10-25): every owner of stored references
    answers for its own data. The core cannot know them all: Folio has fields and blocks,
    BG Genius will have flow JSON and markdown with ids or old paths, and holz-objekte.org
    already has its own `AssetUse`. A central reference table written on every save was
    rejected, because one missed write gives a wrong answer.
  - **What a source owes the core:** it reports every place its data references an asset
    (by id, and by legacy path where its data can hold those). An app that stores asset
    references and registers no source gets no delete protection there. The media README
    says so plainly.
  - **How a source answers is its own choice:** scanning on request (Folio in release 1,
    as today) or a reference index it keeps up to date on save, when scanning gets too
    slow (likely for BG Genius's flows). The interface does not change either way.
- **Private files:**
  - `Preflow\Media\Http\PrivateFileController` serves `{private_url}/{id}` with today's
    headers and rules.
  - Access is a core interface, `Preflow\Media\Access\PrivateFileAccess`, with
    `decide(ServerRequestInterface $request, Asset $asset): ?ResponseInterface`.
    `null` means allow; a response means deny with that response (403 or redirect).
  - The default implementation denies everyone with a 403, failing closed.
  - Folio binds its implementation: today's `FrontendGate`, `private_permission` and
    `private_login` logic.
- **Variants on first request:** `Preflow\Media\Http\VariantController` (today's
  `MediaVariantController`).
- **Routes:**
  - The core registers the two GET routes, the media URL's variant path and the private
    URL, through a provider (`MediaServiceProvider`), as Folio does today. Their order
    against the app's catch-all routes is unchanged.
- **Config:**
  - New: `config/media.php` with today's keys (`dir`, `url`, `storage`, `max_bytes`,
    `max_pixels`, `warm_on_upload`, `allow_svg`, `widths`, `quality`, `private_dir`,
    `private_url`). `private_permission` and `private_login` stay Folio settings in
    `config/folio.php`, because they belong to Folio's access implementation.
  - If `config/media.php` is missing, the core reads `config/folio.php` `assets` and logs
    a deprecation once per boot through the debug collector, never to the page.
  - Defaults are unchanged.
- **Template functions (core):**
  - `media_image(value, preset, options)` and `media_url(value)` are today's
    `folio_image()` and `folio_asset_url()` under new names.
  - Folio keeps registering the old names as aliases.
- **Commands (core):**
  - `media:variants [--all]` is today's `folio:assets:variants`; Folio keeps the old name
    as an alias that prints a deprecation note.
  - `folio:assets:import`, which scans Folio records for legacy upload paths, stays in
    Folio because it is about Folio content.
- **Cache registry:** variants are the media itself, not a cache, so nothing is registered
  in 0.34. A "derived images" source may come with release 2's presets.

### What stays in Folio (the adapter)

- **Admin screens and fields:** `AssetLibraryController` and its templates (library
  screen, details panel, picker drawer), `AssetFieldType`, `UploadController`,
  `AssetController`. They call `MediaLibrary`, `AssetUsage` and `ImageTag` and keep their
  routes, permissions (`assets.*`), texts and markup.
- **Folio-specific pieces:**
  - `FolioRecordReferences` (the usage source);
  - the `PrivateFileAccess` implementation;
  - the `LocaleProvider` binding;
  - `folio_image`/`folio_asset_url` aliases;
  - class aliases for the old `Preflow\Folio\Assets\…` names;
  - `folio:assets:import`;
  - content export/import (`Transfer\ContentSet`), which asks `MediaLibrary` for the
    asset type definition and directories.
- `preflow/folio` requires `preflow/media` (`^0.34 || @dev`), so a Folio site gets the
  library as today.

### Sites

- **smeyer.me and smart-stack.works** switch to the new names in the same work:
  - `config/media.php` instead of `folio.php` `assets` (with `private_permission` staying
    in `folio.php`);
  - `Preflow\Media\…` classes in app code and tests (about 10 files);
  - `media_image()`/`media_url()` in templates.

  Their suites run green on the branch before release, as with every release.
- **holz-objekte.org** is untouched. It runs on the deprecated names until its own
  upgrade.

### Order of work (for the builder)

1. Create the package (composer, split entry, README skeleton, monorepo autoload) and
   move the pure classes: `Asset`, `Variant`, the backends, `VariantGenerator` and
   `ImageWidths`.
2. Move `MediaLibrary`, `ImageTag` with `LocaleProvider`, the usage interface with
   `FolioRecordReferences`, the delete rule, and the HTTP controllers with
   `PrivateFileAccess`.
3. Config, routes, template functions and commands in the core. Folio aliases, class
   aliases and the dependency guard test.
4. Docs: a media README with everything the Folio README's "Asset library" section says
   that is core. Folio's README keeps the UI parts and links to it.
5. Release checklist; migrate the sites.

Each step leaves the whole suite green. Today's Folio asset tests move along with the
code and stay unchanged where they test behaviour.

### Settled details (from the builder check, my calls)

- **Registration and route order:**
  - Sites list providers in `config/providers.php`. `FolioServiceProvider` registers
    `MediaServiceProvider` itself, before its own routes, so a site that lists only Folio
    gets media automatically.
  - `/media/…` and `{private_url}/{id}` are therefore added before Folio's frontend
    catch-all, as today. A test pins this.
  - A site without Folio lists `MediaServiceProvider` itself.
- **The asset table:**
  - The media core owns the type definition (`MediaLibrary::definition()`). It creates
    the table itself through a `MigrationRegistry::afterMigrate('media.table', …)` step,
    so `preflow migrate` works without Folio.
  - The "create missing table and columns from a `TypeDefinition`" piece of Folio's
    `TableSync` moves into `preflow/data` as `Preflow\Data\Schema\RecordTableSync`. Both
    Folio's `TableSync` and the media step use it, and the logic is not duplicated.
  - `TableSync` stops handling the asset table.
- **Config:**
  - `config/media.php` replaces the old block entirely; the two are never merged. The
    fallback to `config/folio.php` `assets` applies only when `media.php` is missing.
    Reading a config file is not a class dependency.
  - Defaulting and validation move into the core, including "a `private_dir` inside
    `public/` or the media directory is refused at boot". The error message names the
    file the value came from.
  - `private_permission` and `private_login` are Folio's access rules. Folio reads them
    from `config/folio.php` `assets.private_permission` / `assets.private_login`, as
    today, whether or not `media.php` exists.
- **Private access default:** the core's default denies with a 403. This matches today:
  `FrontendGate` fails closed. Only Folio, or a site's own binding, opens it.
- **Usage in the screen:**
  - Folio's library controller maps `AssetUsageEntry` objects to today's arrays, so the
    templates stay unchanged.
  - `isUsed()` is `of() !== []` and includes usages found only in published versions, as
    the screen's check does today.
- **Class aliases** (for one release, from a Composer `files` entry in Folio):
  - `Asset`, `Variant`, `AssetUploadException`, `ImageTag`, `ImageWidths`,
    `VariantGenerator`, `Image\*` → their `Preflow\Media\…` classes;
  - `AssetLibrary` → `MediaLibrary`. Its constructor keeps today's parameters, and the
    usage service is an added optional last parameter that the container fills.
  - `AssetReferences` (walks Folio records and blocks for asset ids) is Folio-specific,
    so it **stays** `Preflow\Folio\Assets\AssetReferences` with no alias needed;
    holz-objekte.org's `app/Images/AssetUse.php` uses it.
  - Folio's old `AssetUsage` has no outside users and is replaced, not aliased.
  - What the sites use today: `Asset` (4), `AssetLibrary` (25), `AssetReferences` (1),
    `AssetUploadException` (1).
- **HTML class names stay frozen in release 1:** `folio-focal`, `folio-fx-*`/`folio-fy-*`
  and the preload keys, so the output stays byte-identical. Renaming them is a later,
  visible change.
  - `folio_image()`/`folio_asset_url()` call the same `ImageTag` instance as
    `media_image()`/`media_url()`, so no CSS or preload is added twice.
- **Composer:**
  - `preflow/media` requires `preflow/core`, `preflow/data` and `preflow/view`, with
    `^0.34 || @dev` plus matching conflicts, as `ComposerConstraintsTest` expects.
  - `preflow/i18n` is a `suggest`. `LocaleProvider` falls back to `config/app.php` when
    it is missing.
- **Test baseline:**
  - The number of asset-related tests is recorded before the move (folio `tests/Assets`,
    the asset field, library, variant, private and frontend tests). Every case still
    exists afterwards.
  - Tests of the old `AssetUsage` change shape (they now test `FolioRecordReferences`)
    but keep every case.

## Tests (for the builder)

- **Every existing asset test passes after the move,** under its new namespace. Their
  count is recorded before and after, and no asset test may disappear.
- **Dependency guard:** no file in `packages/media/src` references `Preflow\Folio`.
- **Usage:**
  - a fake `AssetReferenceSource` makes `delete()` throw `AssetInUseException`;
  - with no sources registered, the asset is deleted;
  - `FolioRecordReferences` returns today's usages (the existing `AssetUsageTest`
    cases).
- **Private access:**
  - the core default is a 403;
  - with Folio, today's rules apply (the existing private-asset tests).
- **Legacy names:**
  - an app with only `config/folio.php` `assets` boots and behaves the same;
  - `folio_image()` renders the same HTML as `media_image()`;
  - `new \Preflow\Folio\Assets\AssetLibrary` resolves to the new class (alias);
  - `folio:assets:variants` runs.
- **Byte-identical HTML:** a page rendered through the real app (`AssetFrontendTest`
  style) gives identical HTML before and after, for an image with a preset, `step`,
  `priority` and a focal point.

## Calls made here (my calls, for the owner to check)

- **Package name `preflow/media`,** namespace `Preflow\Media`. The umbrella design left
  the name open between this and `assetmanager`.
- **Nothing in storage changes in release 1:** the table stays `folio_assets`, URLs and
  folders stay as they are. A rename would force a data migration on every site,
  holz-objekte.org's live database included, for no visible gain.
- **One release of deprecated aliases** for class names, template functions, config and
  the command, so holz-objekte.org keeps working on 0.34 untouched.
- **The "refuse deleting a used asset" rule moves into the core's `delete()`.** Today only
  the Folio screen enforces it.
- **`private_permission` and `private_login` stay in Folio's config,** because they are
  Folio's access rules. The core only knows the `PrivateFileAccess` hook.
- **Table creation moves into the core** (a `media.table` migrate step), and the shared
  table-sync piece moves into `preflow/data`, so media works without Folio.

## Size

Large but mechanical: about 20 classes move, three interfaces are new (usage source,
private access, locale), plus two adapters, aliases, config fallback, docs and two site
migrations. One plan of about 5 tasks; release as 0.34.0.
