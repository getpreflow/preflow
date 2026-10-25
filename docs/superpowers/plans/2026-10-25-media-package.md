# Media Package, Release 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move Folio's asset library into a new package, `preflow/media`, with a core that does everything and Folio as an adapter that only adds UI. There are no visible changes, the old names keep working for one release, and the release is v0.34.0.

**Architecture:**
- The asset classes move to `Preflow\Media` and gain three core interfaces: the usage source, private-file access and the locale provider.
- `MediaServiceProvider` owns config, services, routes, template functions, the command and the table step.
- `FolioServiceProvider` registers the media provider first, binds its adapters (record usage, private access, content locale) and keeps the screens and field.
- Old class names, template functions, config and the command are aliases for one release.

**Tech Stack:** PHP 8.4, PHPUnit 11, Twig, GD/Imagick.

**Spec:** `docs/superpowers/specs/2026-10-25-media-package-design.md`. Read its "Settled details" section; every choice there binds.

## Global Constraints

- **Package and namespace:** package `preflow/media`, namespace `Preflow\Media`, tests `Preflow\Media\Tests`.
- **Dependency rule:** no file in `packages/media/src` references `Preflow\Folio`. This is enforced by `packages/media/tests/NoFolioDependencyTest.php`.
- **Composer:**
  - `preflow/media` requires `preflow/core`, `preflow/data` and `preflow/view` at `^0.34 || @dev`, each with a matching `conflict` `<0.34`.
  - It suggests `preflow/i18n`.
  - `preflow/folio` requires `preflow/media` `^0.34 || @dev` and conflicts with `<0.34`.
- **Monorepo wiring:** like `packages/codes`. Add entries to:
  - the root `composer.json` (`require` `@dev`, the path repository, and `autoload-dev`);
  - `phpunit.xml` (a testsuite "Media" and the source directory);
  - `.github/workflows/split.yml` (`{ local: 'packages/media', remote: 'media' }`).
- **Nothing visible changes:**
  - storage stays the same: the `folio_assets` table, `public/media/Y/m/{id}.{ext}`, `storage/private-media`, `/media/…` and `/files/{id}`;
  - admin texts and markup stay the same;
  - the frontend HTML is byte-identical, including the classes `folio-focal`, `folio-fx-*` and `folio-fy-*` and the preload keys.
- **Deprecated for one release, still working:**
  - `Preflow\Folio\Assets\{Asset, Variant, AssetUploadException, ImageTag, ImageWidths, VariantGenerator, Image\*}` and `AssetLibrary` (now `MediaLibrary`), as class aliases from a Composer `files` entry in `packages/folio`;
  - `folio_image()` and `folio_asset_url()`;
  - `config/folio.php` `assets` (read only when `config/media.php` is missing; never merged);
  - `folio:assets:variants`.
- **Stays in Folio unchanged:** `Preflow\Folio\Assets\AssetReferences` (holz-objekte.org uses it), `folio:assets:import`, and the screens, field and controllers for upload and legacy files.
- **Private access settings:** `private_permission` and `private_login` are read by Folio from `config/folio.php` `assets.private_permission` and `assets.private_login`, with defaults `folio.dashboard` and `null`. With no binding, the core denies access with a 403.
- **Gates:**
  - `vendor/bin/phpunit` (baseline v0.33.0: 3002 tests, 99 deprecations; add none);
  - `composer test:mysql`;
  - the smeyer.me and smart-stack.works suites (smart-stack.works has one known failure from 0.33: `MembersGroundworkTest::test_sidebar_structure`).
  - Do not touch holz-objekte.org. Do not merge, push or tag without the owner's go-ahead.
- **Asset test baseline** (cases that must all still exist afterwards, moved or kept):

  | File | Tests |
  |---|---|
  | `AssetFieldLibraryTest` | 13 |
  | `PrivateAssetsTest` | 13 |
  | `MediaVariantControllerTest` | 12 |
  | `AssetLibraryTest` | 12 |
  | `ImageTagTest` | 11 |
  | `AssetLibraryAppTest` | 10 |
  | `VariantGeneratorTest` | 10 |
  | `AssetCommandsTest` | 6 |
  | `ImageWidthsTest` | 6 |
  | `AssetUsageTest` | 5 |
  | `AssetFrontendTest` | 2 |
  | `RichTextOutputTest` | 2 |

## Review Focus

- **A site listing both `MediaServiceProvider` and `FolioServiceProvider`** in `config/providers.php`, in either order: media is registered once, its routes are added once, and they come before Folio's frontend catch-all (Task 4).
- **A site with `config/media.php` and a leftover `config/folio.php` `assets` block:** `media.php` wins entirely; nothing is merged and no deprecation is logged (Task 3).
- **Deleting an asset referenced only in a published version:** refused by the core's `delete()`, with the usage listed (Task 3).
- **`folio_image()` and `media_image()` used on the same page for the same image:** the focal CSS and the preload link are added once (Task 4).
- **A private asset requested on a site whose Folio has no auth configured:** a 403, as today (Task 4).

---

### Task 1: Shared record-table sync in `preflow/data`

**Files:**
- Create: `packages/data/src/Schema/RecordTableSync.php`, `packages/data/tests/Schema/RecordTableSyncTest.php`
- Modify: `packages/folio/src/Storage/TableSync.php` (use it; behaviour unchanged)

**Interfaces — Produces:**
- `RecordTableSync::__construct(DataManager $dm)`
- `RecordTableSync::sync(TypeDefinition $def): list<string>`. It creates the missing table and columns of an SQL-stored definition, with the same lines and DDL `TableSync` emits today. A JSON-stored definition returns `[]`. Nothing is ever dropped or altered.

- [ ] **Step 1:** Write the tests, on SQLite in a temp file:
  - a new table is created and the returned line equals today's `TableSync` line for it;
  - a second run returns `[]`;
  - a field added to the definition adds the column;
  - a JSON definition returns `[]`.
- [ ] **Step 2:** Run `vendor/bin/phpunit packages/data/tests/Schema` and see it fail.
- [ ] **Step 3:** Extract the per-definition logic from `TableSync` into `RecordTableSync`. `TableSync` calls it for each definition and keeps its order and output.
- [ ] **Step 4:** Run `vendor/bin/phpunit packages/data packages/folio/tests/Storage` and see it pass. Run the full suite once; it stays green.
- [ ] **Step 5:** Commit `refactor(data): RecordTableSync for one type's table, used by Folio's TableSync`.

### Task 2: Package skeleton and the pure classes

**Files:**
- Create:
  - `packages/media/{composer.json, README.md (skeleton), LICENSE (copy)}`;
  - `packages/media/src/{Asset.php, Variant.php, AssetUploadException.php}`;
  - `packages/media/src/Image/{ImageBackend, GdBackend, ImagickBackend, ImageBackends, Orientation, VariantGenerator, ImageWidths}.php`;
  - `packages/media/tests/NoFolioDependencyTest.php`;
  - `packages/media/tests/ComposerConstraintsTest.php` (same rule as Folio's);
  - `packages/folio/src/legacy-aliases.php`.
- Move: `packages/folio/src/Assets/{Asset,Variant,AssetUploadException,VariantGenerator,ImageWidths}.php` and `Assets/Image/*` go to the paths above with `git mv`, changing the namespace only. Their tests `VariantGeneratorTest` and `ImageWidthsTest` move to `packages/media/tests/Image/`.
- Modify:
  - `packages/folio/composer.json` (require and conflict on media; `autoload.files: ["src/legacy-aliases.php"]`);
  - the root `composer.json`, `phpunit.xml` and `split.yml` (Global Constraints);
  - every Folio file importing the moved classes.

**Interfaces — Produces:**
- `Preflow\Media\Asset`, `Variant` and `AssetUploadException` (unchanged APIs).
- `Preflow\Media\Image\VariantGenerator`, `ImageWidths`, `ImageBackend`, `ImageBackends`, `GdBackend`, `ImagickBackend` and `Orientation`.
- `legacy-aliases.php`:
  - each old name gets `class_alias(New::class, 'Preflow\Folio\Assets\Old')`, guarded by `class_exists(Old, false)`;
  - `Image\*` maps to `Preflow\Media\Image\*`;
  - `VariantGenerator` and `ImageWidths` map from `Preflow\Folio\Assets\X` to `Preflow\Media\Image\X`.

- [ ] **Step 1:** Write the tests:
  - **`NoFolioDependencyTest`:** grep `packages/media/src` for `Preflow\Folio` and `Preflow\\Folio`; expect no hits.
  - **`LegacyAliasesTest`** in `packages/folio/tests`:
    - `new \ReflectionClass('Preflow\Folio\Assets\Asset')` gives the name `Preflow\Media\Asset`;
    - the same holds for each alias listed.
  - **Media `ComposerConstraintsTest`:** the require and conflict pairs from Global Constraints.
  - **Byte-identical HTML fixture:** extend `packages/folio/tests/Integration/AssetFrontendTest.php`. Before any code moves, render an image with a preset, `step`, `priority` and a focal point through the real app. Store the HTML as `tests/Fixtures/asset-frontend.html` and assert equality. This test passes now and must stay green, unchanged, through Tasks 3 and 4.
- [ ] **Step 2:** The tests fail, except the fixture test, which passes.
- [ ] **Step 3:** Do the moves, wiring and aliases. Run `composer dump-autoload`.
- [ ] **Step 4:** Run `vendor/bin/phpunit packages/media packages/folio` and see it pass. Run the full suite; it stays green with the same count.
- [ ] **Step 5:** Commit `feat(media): preflow/media package with the asset value classes and image backends`.

### Task 3: Core services — config, library, usage, locale, image tag, table step

**Files:**
- Create in `packages/media/src/`:
  - `MediaConfig.php`;
  - `MediaLibrary.php` (`git mv` from `folio/src/Assets/AssetLibrary.php`);
  - `ImageTag.php` (`git mv`);
  - `LocaleProvider.php`, `ConfigLocaleProvider.php`;
  - `Usage/{AssetReferenceSource, AssetUsageEntry, AssetUsage, AssetInUseException}.php`;
  - `MediaServiceProvider.php`.
- Create in Folio:
  - `packages/folio/src/Media/FolioRecordReferences.php`, made from Folio's old `Assets/AssetUsage.php` logic (that file is deleted);
  - `packages/folio/src/Media/ContentLocaleProvider.php`.
- Modify:
  - `FolioServiceProvider` (see the Folio bullet below);
  - `AssetLibraryController`, which maps entries to today's arrays and lets the core refuse deletes;
  - `Transfer\ContentSet`, which uses `MediaConfig`;
  - every Folio user of `AssetLibrary` and `ImageTag`;
  - the aliases `AssetLibrary` → `MediaLibrary` and `ImageTag`.
- Move tests:
  - `AssetLibraryTest` and `ImageTagTest` go to `packages/media/tests/`;
  - `AssetUsageTest` becomes `packages/folio/tests/Media/FolioRecordReferencesTest.php` (same cases).

**Interfaces:**
- **Consumes:** Task 1 `RecordTableSync`; Task 2 classes.
- **Produces:**
  - **`MediaConfig`:**
    - `MediaConfig::fromApp(Application $app, ?DebugCollector $debug = null): self`;
    - readonly public properties for today's keys: `dir`, `url`, `storage`, `maxBytes`, `maxPixels`, `warmOnUpload`, `allowSvg`, `widths`, `quality`, `privateDir` and `privateUrl`;
    - `source(): string` returns `'config/media.php'` or `'config/folio.php assets'`.
    - Defaults are as today. It refuses a `privateDir` inside `public/` or the media dir with a `\RuntimeException` that names `source()`.
    - The fallback logs `config/folio.php "assets" is deprecated; move it to config/media.php` once, through the collector, and only when it has one.
  - **`MediaLibrary`:**
    - today's `AssetLibrary` constructor plus a last parameter, `?AssetUsage $usage = null`;
    - `delete(string $id): void` throws `AssetInUseException` (with `public array $usages`, a `list<AssetUsageEntry>`) when `$usage?->isUsed($id)`.
  - **Usage:**
    - `interface AssetReferenceSource { name(): string; usagesOf(string $assetId): list<AssetUsageEntry>; }`
    - `final readonly class AssetUsageEntry(string $label, ?string $url, string $source, ?string $note = null)`
    - `AssetUsage`: `register(AssetReferenceSource)`, `of(string): list<AssetUsageEntry>` (sources in registration order), `isUsed(string): bool`
  - **Locale:** `interface LocaleProvider { current(): string; default(): string; }` with `ConfigLocaleProvider`, which reads the i18n `Translator` locale if bound, else `app.locale`, else `'en'`.
  - **`ImageTag`:** today's constructor, with `ContentLocale` replaced by `LocaleProvider` and the legacy uploads URL as a plain string parameter.
  - **`MediaServiceProvider::register()`:**
    - binds `MediaConfig`, `ImageWidths`, `MediaLibrary` (with `AssetUsage`), `AssetUsage` (singleton, empty), `LocaleProvider` (as `ConfigLocaleProvider`) and `ImageTag` (singleton);
    - when `storage` is SQL, adds `afterMigrate('media.table', …)` using `RecordTableSync` on `MediaLibrary::definition()`.
  - **Folio** registers `MediaServiceProvider` at the start of its `register()`, unless it is already registered. Then it:
    - rebinds `LocaleProvider` to `ContentLocaleProvider`;
    - registers `FolioRecordReferences` into `AssetUsage`;
    - drops the asset table from `TableSync`.

- [ ] **Step 1: Write the tests (new ones besides the moved).**
  - **`MediaConfigTest`:**
    - `media.php` only: its values are used, with `source() === 'config/media.php'`;
    - `folio.php` `assets` only: the same values, the fallback source, and one deprecation entry in the collector;
    - both present: `media.php` wins and the two are not merged (a key only in the `folio.php` block is absent);
    - a `privateDir` under `public/` throws with the source in the message.
  - **`AssetUsageTest`** (media):
    - a fake source makes `isUsed` true and `of()` return its entries in order;
    - no sources means not used.
  - **`MediaLibraryDeleteTest`:**
    - a used asset gives `AssetInUseException` with the entries, and the file and record remain;
    - an unused asset is deleted.
  - **`FolioRecordReferencesTest`:**
    - the old `AssetUsageTest` cases;
    - plus "asset used only in a published version" gives an entry with a note, and `MediaLibrary::delete()` refuses.
  - **`MediaTableStepTest`:** an app with `media.php` `storage => 'sqlite'` and no Folio provider gets `folio_assets` created by `preflow migrate` (`MigrateCommand` with an app factory).
  - **Library screen:**
    - the existing `AssetLibraryAppTest` passes unchanged (usage list markup and delete refusal);
    - `ContentLocaleProvider` gives `ImageTag` the content locale (an existing `ImageTagTest` case with a German page).
- [ ] **Step 2:** The tests fail.
- [ ] **Step 3:** Implement and move.
- [ ] **Step 4:** Run `vendor/bin/phpunit packages/media packages/folio packages/data` and see it pass. The full suite is green, and its count is at least the baseline plus the new tests.
- [ ] **Step 5:** Commit `feat(media): library, usage sources, locale and image tag in the core; Folio adapts`.

### Task 4: HTTP, routes, template functions, command and private access

**Files:**
- Create in `packages/media/src/`:
  - `Http/VariantController.php` (`git mv` from Folio's `MediaVariantController`);
  - `Http/PrivateFileController.php` (from `PrivateAssetController`, without `FrontendGate`);
  - `Access/{PrivateFileAccess, DenyAllPrivateFileAccess}.php`;
  - `Routing/MediaRoutes.php`;
  - `Console/VariantsCommand.php` (from `AssetsVariantsCommand`).
- Create in Folio: `packages/folio/src/Media/FolioPrivateFileAccess.php`, carrying today's gate, `private_permission` and `private_login` logic.
- Modify:
  - `MediaServiceProvider`: bind the controllers, `PrivateFileAccess` (as `DenyAllPrivateFileAccess`) and the routes in `boot()`; add the template functions `media_image` and `media_url`.
  - `packages/media/composer.json`: `extra.preflow.commands` gets `VariantsCommand`.
  - `FolioServiceProvider`:
    - bind `PrivateFileAccess` to `FolioPrivateFileAccess`;
    - remove its media and private routes and bindings;
    - keep `folio_image` and `folio_asset_url`, calling the same `ImageTag` singleton.
  - `packages/folio/src/Console/AssetsVariantsCommand.php`: a thin alias that prints `Note: "folio:assets:variants" is deprecated; use "media:variants".` and delegates.
  - `FolioRoutes`: remove `media()` and `privateAssets()`.
- Move tests:
  - `MediaVariantControllerTest` goes to `packages/media/tests/Http/`;
  - `PrivateAssetsTest` splits into core cases (headers, 404s, default 403) in media and gate cases in Folio;
  - `AssetCommandsTest`'s variants cases go to media; its import cases stay.

**Interfaces:**
- **Consumes:** Task 3 `MediaConfig`, `MediaLibrary`, `ImageTag` and `ImageWidths`.
- **Produces:**
  - `interface PrivateFileAccess { decide(ServerRequestInterface $request, Asset $asset): ?ResponseInterface; }`, where `null` allows.
  - `MediaRoutes::register(RouteCollection $routes, MediaConfig $config): void` adds the private route and the media variant route (same paths, methods and handlers as today). It is idempotent per collection, so a second call adds nothing.
  - `MediaServiceProvider::boot()` calls `MediaRoutes::register()`. Because Folio registers media before itself, media boots first, and its routes come after the app's routes and before Folio's routes and frontend catch-all.
  - `media:variants [--all]` keeps today's output.

- [ ] **Step 1:** Write the tests:
  - **`MediaRoutesTest`:**
    - routes are added once, even after two calls;
    - in an app listing `[Media, Folio]` and in one listing `[Folio, Media]` in `config/providers.php`:
      - `/media/2026/10/{id}-480.webp` reaches `VariantController`;
      - `/files/{id}` reaches `PrivateFileController`;
      - a page slug still reaches Folio's frontend.
  - **Private access:**
    - the core alone gives 403 for `/files/{id}`;
    - with Folio, the moved gate cases pass, including a 403 with no auth configured and the `private_login` redirect.
  - **Template functions:**
    - `media_image(id, 'hero', {priority: true})` HTML equals `folio_image(...)` HTML;
    - both on one page add the focal CSS and the preload link once.
  - **Commands:** `media:variants --all` makes the step widths; `folio:assets:variants` prints the note and does the same.
  - The Task 2 fixture test (`AssetFrontendTest`) stays green, with the fixture unchanged.
- [ ] **Step 2:** The new tests fail.
- [ ] **Step 3:** Implement and move.
- [ ] **Step 4:** Run `vendor/bin/phpunit packages/media packages/folio packages/rbac` and see it pass. The full suite is green and the asset baseline cases all exist; record the counts in the report.
- [ ] **Step 5:** Commit `feat(media): variant and private-file serving, routes, media_image/media_url and media:variants in the core`.

### Task 5: Docs, site migration and release prep

**Files:**
- Modify:
  - `packages/media/README.md`: everything core from the Folio README's "Asset library":
    - config (`config/media.php`), variants, step widths, private files with the `PrivateFileAccess` hook;
    - usage sources, including "if you store references, register a source", and that a source may scan or keep an index;
    - `media_image()`/`media_url()`, `media:variants`, and use without Folio.
  - `packages/folio/README.md`: the UI parts, a link to the media README, and the deprecations.
  - `ROADMAP.md`: a 0.34.0 entry.
  - The root `README.md` package list.
- Create: `docs/superpowers/plans/2026-10-25-release-0.34-checklist.md`, shaped like the 0.33 one, with upgrade notes covering:
  - the renames table (old → new);
  - aliases for one release;
  - `config/media.php`;
  - private settings staying in `folio.php`;
  - `AssetReferences` staying in Folio;
  - the core now refusing to delete used assets;
  - `preflow/media` being a new split repository that must exist on GitHub and be submitted to Packagist **before the tag** (owner's step).
- **Sites** (edit and commit in each site repo; don't push):
  - **smeyer.me** (`/Users/smyr/Sites/gbits/smeyer.me`) and **smart-stack.works** (`/Users/smyr/Sites/gbits/smart-stack.works`):
    - move `config/folio.php` `assets` to `config/media.php`, keeping `private_permission` and `private_login` in `folio.php` `assets`;
    - use `Preflow\Media\…` classes in `app/` and `tests/`;
    - use `media_image()`/`media_url()` in templates;
    - commit `chore: preflow/media names (0.34)`.
  - Before running each site's suite, delete its stale `storage/cache/folio-*.cache`.
  - On smart-stack.works, the known sidebar failure stays recorded and is not fixed here.
  - **holz-objekte.org:** do not touch.

- [ ] Run the full suite, `composer test:mysql` and both site suites. Record the counts and the asset-baseline check in the checklist.
- [ ] Commit `chore: prepare 0.34.0`. No merge, push or tag.
