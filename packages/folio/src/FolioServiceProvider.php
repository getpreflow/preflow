<?php

declare(strict_types=1);

namespace Preflow\Folio;

use Preflow\Blocks\BlockRegistry;
use Preflow\Core\Application;
use Preflow\Core\Composer\InstalledPackages;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Routing\RouterInterface;
use Preflow\Data\DataManager;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\Content\DraftRecordFactory;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\Customization\Customizations;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\ModelExtension;
use Preflow\Folio\Extension\Settings\ExtensionSettings;
use Preflow\Folio\Extension\Screen\ScreenRegistry;
use Preflow\Folio\Http\AdminAuthMiddleware;
use Preflow\Folio\Http\AdminController;
use Preflow\Folio\Http\AssetController;
use Preflow\Folio\Http\AuthController;
use Preflow\Folio\Http\FrontendController;
use Preflow\Folio\Http\PreviewController;
use Preflow\Folio\Http\UploadController;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\Types\AssetFieldType;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Folio\Field\Types\BooleanFieldType;
use Preflow\Folio\Field\Types\DateFieldType;
use Preflow\Folio\Field\Types\EnvFieldType;
use Preflow\Folio\Field\Types\NumberFieldType;
use Preflow\Folio\Field\Types\SelectFieldType;
use Preflow\Folio\Field\Types\RichTextFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\MatrixFieldType;
use Preflow\Folio\Field\Types\RelationFieldType;
use Preflow\Folio\Field\Types\TextFieldType;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Override\ActionResolver;
use Preflow\Folio\Routing\FolioRoutes;
use Preflow\Routing\Router;
use Preflow\View\TemplateEngineInterface;

final class FolioServiceProvider extends ServiceProvider
{
    /** @var array{0: string, 1: array<string, mixed>}|null config/folio.php, read once */
    private ?array $folioConfigCache = null;

    /** How often config/folio.php was actually loaded (for tests). */
    public int $folioConfigReads = 0;

    public function register(Container $container): void
    {
        // Folio's admin ships its own htmx (@folio/htmx.js), whatever the app's hypermedia driver
        // is: its requests are htmx requests. Added as a client so core treats them as such (an
        // expired session reloads, the security headers send HX-Nonce); with an htmx app it is
        // the same class as the app's driver and ignored.
        if ($container->bound(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class)) {
            $container->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class)
                ->add(new \Preflow\Htmx\HtmxDriver(new \Preflow\Htmx\ResponseHeaders()));
        }
        // The media core first (once, also when the app lists it): Folio adapts its services below.
        if (!$container->hasProvider(\Preflow\Media\MediaServiceProvider::class)) {
            $container->registerProvider(new \Preflow\Media\MediaServiceProvider());
        }
        // The AI core likewise: Folio's AI features adapt its services, which stay off until config/ai.php enables them.
        if (!$container->hasProvider(\Preflow\Ai\AiServiceProvider::class)) {
            $container->registerProvider(new \Preflow\Ai\AiServiceProvider());
        }
        // The blocks core: Folio keeps the blocks field and its editor, and answers the
        // blocks' questions (content locale, rich text, preview marks) below.
        if (!$container->hasProvider(\Preflow\Blocks\BlocksServiceProvider::class)) {
            $container->registerProvider(new \Preflow\Blocks\BlocksServiceProvider());
        }
        // The publishing core: Folio binds its rules (which types publish, validation, languages,
        // the review index, redirects and page-cache tags) below and keeps the screens.
        if (!$container->hasProvider(\Preflow\Publishing\PublishingServiceProvider::class)) {
            $container->registerProvider(new \Preflow\Publishing\PublishingServiceProvider());
        }
        // Machine translation from the i18n core, built on the AI core above.
        if (!$container->hasProvider(\Preflow\I18n\Machine\MachineServiceProvider::class)) {
            $container->registerProvider(new \Preflow\I18n\Machine\MachineServiceProvider());
        }
        $app = $container->get(Application::class);
        $modelsPath = $this->modelsPath($app);
        $prefix = $this->prefix($app);

        // The DataManager's registry, so types defined at runtime (extension models) are the ones
        // it queries; given one when it has none (a site without a models directory: extension
        // models need no files). Without a DataManager, Folio's own. Bound unconditionally:
        // Container::has() falls back to class_exists(), so a has() guard would never bind it.
        $locales = $this->locales($container);
        $dataManager = $this->dataManager($container, $app);
        $own = new TypeRegistry($modelsPath, $locales);
        $types = $dataManager?->ensureTypeRegistry($own) ?? $own;
        $container->instance(TypeRegistry::class, $types);

        // Built on the registry: a model file whose key a model class defines lists that class.
        $catalog = new TypeCatalog($modelsPath, $app->bootManifest(), $types);
        $container->instance(TypeCatalog::class, $catalog);

        // Record user providers named in config/auth.php get their user type from Folio's catalog.
        $recordProvider = static fn (Container $c, array $parameters = []): \Preflow\Auth\Record\RecordUserProvider => new \Preflow\Auth\Record\RecordUserProvider(
            $c->get(DataManager::class),
            (array) ($parameters['config'] ?? []),
            \Preflow\Folio\Users\RecordProviders::types($c->get(TypeCatalog::class), $c->get(TypeRegistry::class)),
        );
        $container->bind(\Preflow\Auth\Record\RecordUserProvider::class, $recordProvider);

        // Extensions (app/Folio/*, Composer extra.folio.extension), enabled by the committed
        // config/folio/extensions.json. Built before anything reads the catalog, so the models
        // of enabled extensions are types like the model files (frontend routes, permissions).
        // From the boot manifest when it has them: it is used only while extensions.json is unchanged.
        $manifest = $app->bootManifest();
        $extensions = ExtensionRegistry::fromArray($manifest->remember('folio.extensions', fn (): array => ExtensionRegistry::discover(
            $app->basePath(),
            debug: (bool) $app->config()->get('app.debug', false),
            cacheFile: $this->cacheDir($app) . '/folio-extensions.cache',
            composerCache: $app->basePath('storage/cache/composer'),
        )->toArray(), [$app->basePath('app/Folio')]));
        $container->instance(ExtensionRegistry::class, $extensions);
        // The members admin (the built-in extension "members", with preflow/members), when it is
        // switched on: the members core reads the owner's settings from Settings → Members and
        // links the owner's notice to the Decision panel; its pages set Folio's content locale. Bound whichever provider registers first (the core
        // binds its config-based settings only when nothing is bound, and a later binding wins).
        if (self::membersAdmin($extensions)) {
            $container->singleton(\Preflow\Members\MembersSettings::class, static fn (Container $c): \Preflow\Members\MembersSettings => new \Preflow\Folio\Members\FolioMembersSettings(
                $c->get(ExtensionSettings::class),
            ));
            // The owner's notice links the member's edit page (the Decision panel).
            $appUrl = $app->config()->get('app.url');
            $container->instance(\Preflow\Members\MemberLinks::class, new \Preflow\Folio\Members\FolioMemberLinks(
                is_string($appUrl) && $appUrl !== '' ? $appUrl : 'http://localhost',
                $prefix,
            ));
        }
        if (class_exists(\Preflow\Members\MembersDefaults::class)) {
            // The member pages keep the site's Folio page layout unless config/members.php names one;
            // a site without that template (a fresh starter) gets the members' own standalone page:
            // the members check the template when a page renders, whatever order the providers boot in.
            $container->instance(\Preflow\Members\MembersDefaults::class, new \Preflow\Members\MembersDefaults(layout: '@folio/frontend/_site.twig'));
        }
        if (interface_exists(\Preflow\Members\PageLocale::class)) {
            $container->singleton(\Preflow\Members\PageLocale::class, static fn (Container $c): \Preflow\Members\PageLocale => new \Preflow\Folio\Members\FolioPageLocale(
                $c->get(ContentLocale::class),
            ));
        }
        // Models go under Content unless their JSON names a "section"; their settings forms
        // (Extension::settings()) are singleton types under Settings.
        // Each extension may adjust its models first (prepareModel: storage, options from config).
        // Their labels are translated in the extension's translation group (#[FolioExtension(translations: …)]).
        // The schemas come from the boot manifest when it has them (with the site's "extends" files
        // merged in): model(), prepareModel() and settings() depend only on code and config files.
        [$extensionTypes, $labelSources] = $manifest->remember(
            'folio.extension_types',
            fn (): array => $this->extensionTypes($container, $extensions, $modelsPath),
            [$modelsPath],
        );
        foreach ($extensionTypes as [$schema, $section, $what, $group]) {
            try {
                $types->define($schema);
                $catalog->add($schema, $section, $group);
            } catch (\RuntimeException $e) {
                throw new \Preflow\Folio\Extension\ExtensionException("{$what} \"{$schema['key']}\": {$e->getMessage()}", 0, $e);
            }
        }
        $displayLabels = new \Preflow\Folio\Content\DisplayLabels($catalog, $types, $extensions);
        $container->instance(\Preflow\Folio\Content\DisplayLabels::class, $displayLabels);
        $container->instance(\Preflow\Data\TypeLabels::class, new \Preflow\Folio\Content\DisplayTypeLabels(
            $displayLabels,
            static fn (): \Preflow\I18n\Translator => $container->get(\Preflow\I18n\Translator::class),
        ));
        $this->registerLabelKeys($container, $modelsPath, $labelSources, $types, $catalog);
        $folioCfg = $this->folioConfig($app);
        // Folio's answers to the blocks' questions, unless the app bound its own (preflow/blocks fills the rest at boot).
        if (!$container->bound(\Preflow\Blocks\BlockPreviewMarks::class)) {
            $container->singleton(\Preflow\Blocks\BlockPreviewMarks::class, static fn (): \Preflow\Blocks\BlockPreviewMarks => new \Preflow\Folio\Content\FolioBlockPreviewMarks());
        }

        // Rebuilt on the next request; Settings → Cache and cache:clear list it (and the blocks cache, preflow/blocks').
        $caches = $container->get(\Preflow\Core\Cache\CacheRegistry::class);
        $caches->register(new \Preflow\Core\Cache\FileCache('folio.extensions', 'Folio extensions', $this->cacheDir($app) . '/folio-extensions.cache'));
        // Admin text overrides (Settings → Translations), compiled per locale.
        $overridesCache = new \Preflow\Folio\Translations\OverridesCache($this->cacheDir($app));
        $container->instance(\Preflow\Folio\Translations\OverridesCache::class, $overridesCache);
        $caches->register($overridesCache);
        $container->singleton(\Preflow\Folio\Translations\StoredOverrides::class, fn (Container $c) => new \Preflow\Folio\Translations\StoredOverrides(
            $c->get(DataManager::class),
            $c->get(\Preflow\Folio\Translations\OverridesCache::class),
            // Every writer goes through the rules: offered language, editable key, plain text, placeholders.
            new \Preflow\I18n\OverrideRules(new \Preflow\Folio\Translations\EditableOverrideScope(
                $c->get(\Preflow\Folio\Translations\EditableTexts::class),
                $c->get(\Preflow\Folio\Http\InterfaceLocales::class),
            )),
        ));

        // One request-scoped content locale shared by the frontend, preview and field types.
        $contentLocale = new ContentLocale($locales ?? \Preflow\Data\Locales::single('en'));
        $container->instance(ContentLocale::class, $contentLocale);
        // Blocks render in it too (preflow/blocks asks BlockLocale), unless the app bound its own.
        if (!$container->bound(\Preflow\Blocks\BlockLocale::class)) {
            $container->instance(\Preflow\Blocks\BlockLocale::class, $contentLocale);
        }
        // GEO renders pages internally (Markdown views): each keeps its content locale to itself.
        if ($container->bound(\Preflow\View\Geo\RenderScopes::class)) {
            $container->get(\Preflow\View\Geo\RenderScopes::class)->add(
                static fn (\Psr\Http\Message\ServerRequestInterface $sub, \Closure $next): \Psr\Http\Message\ResponseInterface => $contentLocale->scoped($next),
            );
        }
        // The published pages are the site's public pages (sitemap with SEO, llms.txt with GEO);
        // llms.txt takes its name and summary from the site singleton, SEO its defaults and share image.
        $geo = $container->bound(\Preflow\View\Geo\GeoConfig::class) ? $container->get(\Preflow\View\Geo\GeoConfig::class) : null;
        $seo = $container->bound(\Preflow\View\Seo\SeoConfig::class) ? $container->get(\Preflow\View\Seo\SeoConfig::class) : null;
        if (($geo?->enabled() || $seo?->enabled()) && $container->bound(\Preflow\View\Seo\PublicPages::class)) {
            $container->singleton(\Preflow\Folio\Seo\FolioPublicPages::class, static fn (Container $c) => new \Preflow\Folio\Seo\FolioPublicPages(
                $seo,
                $geo,
                $c->get(TypeCatalog::class),
                $c->get(\Preflow\Publishing\Publisher::class),
                $c->get(FrontendResolver::class),
            ));
            $container->get(\Preflow\View\Seo\PublicPages::class)->source(
                static fn (string $locale): array => $container->get(\Preflow\Folio\Seo\FolioPublicPages::class)($locale),
            );
        }
        if ($geo?->enabled() && $container->bound(\Preflow\View\Geo\GeoEntries::class)) {
            $container->get(\Preflow\View\Geo\GeoEntries::class)->site(static fn (string $locale): array => $container->get(\Preflow\Folio\Content\Singletons::class)
                ->record('site')?->localized($locale)->toArray() ?? []);
        }
        if ($seo?->enabled() && $container->bound(\Preflow\View\Seo\SeoSite::class)) {
            $container->singleton(\Preflow\Folio\Seo\FolioPageMeta::class, static fn (Container $c) => new \Preflow\Folio\Seo\FolioPageMeta(
                $seo,
                $c->get(\Preflow\View\Seo\SchemaRegistry::class),
                $c->get(\Preflow\Media\MediaLibrary::class),
                $c->get(\Preflow\Folio\Content\Singletons::class),
                $geo?->noindexField, // kept out of the sitemap and llms.txt: no cards either
            ));
            $site = $container->get(\Preflow\View\Seo\SeoSite::class);
            $site->defaults(static fn (string $locale): \Preflow\View\Seo\PageMeta => $container->get(\Preflow\Folio\Seo\FolioPageMeta::class)->siteDefaults($locale));
            // The admin: kept from crawlers, no share cards; its legacy upload URLs are images public pages show.
            // Folio at "/" would disallow the whole site, so nothing is kept out there.
            $admin = rtrim($prefix, '/');
            if ($admin !== '') {
                $site->disallow($admin . '/');
                $site->allow($admin . '/_uploads/');
            }
        }

        // Media library (preflow/media): its config, library, usage, locale and image tag. Folio adapts it:
        // its content locale, its records as a usage source, the legacy upload URL and the old class names.
        $assetsConfig = self::privateAccessConfig((array) ($folioCfg['assets'] ?? []));
        $container->singleton(\Preflow\Media\LocaleProvider::class, fn (Container $c) => new \Preflow\Folio\Media\ContentLocaleProvider(
            $c->get(ContentLocale::class),
        ));
        $container->singleton(\Preflow\Media\ImageTag::class, fn (Container $c) => new \Preflow\Media\ImageTag(
            $c->get(\Preflow\Media\MediaLibrary::class),
            $c->get(\Preflow\Media\LocaleProvider::class),
            (array) $app->config()->get('image.presets', []),
            $c->get(\Preflow\View\AssetCollector::class),
            rtrim($prefix, '/') . '/_uploads',
            $c->get(\Preflow\Media\Image\ImageWidths::class),
            $c->get(\Preflow\Media\Image\Formats::class),
        ));
        $container->singleton(\Preflow\Folio\Assets\AssetReferences::class, fn (Container $c) => new \Preflow\Folio\Assets\AssetReferences(
            $c->get(BlockRegistry::class),
        ));
        $container->singleton(\Preflow\Folio\Media\FolioRecordReferences::class, fn (Container $c) => new \Preflow\Folio\Media\FolioRecordReferences(
            $c->get(\Preflow\Folio\Assets\AssetReferences::class),
            $c->get(DataManager::class),
            $c->get(TypeCatalog::class),
            $c->get(TypeRegistry::class),
            $prefix,
        ));
        // The core's poster usages link to the poster's video on the library screen.
        $container->singleton(\Preflow\Media\Usage\PosterReferences::class, fn (Container $c) => new \Preflow\Media\Usage\PosterReferences(
            static fn (): \Preflow\Media\MediaLibrary => $c->get(\Preflow\Media\MediaLibrary::class),
            static fn (\Preflow\Media\Asset $asset): string => $prefix . '/assets/' . $asset->id,
        ));
        // Built on first use: the block registry and the library are not needed at boot.
        $container->get(\Preflow\Media\Usage\AssetUsage::class)->register(new class ($container) implements \Preflow\Media\Usage\AssetReferenceSource {
            public function __construct(private readonly Container $container) {}

            public function name(): string
            {
                return \Preflow\Folio\Media\FolioRecordReferences::NAME;
            }

            public function usagesOf(string $assetId): array
            {
                return $this->container->get(\Preflow\Folio\Media\FolioRecordReferences::class)->usagesOf($assetId);
            }
        });

        // `preflow migrate` creates the tables of SQL-stored types from their model JSON
        // (the asset table is the media package's own media.table step).
        // With extensions, the install state's table too (folio:extensions:apply runs the same sync);
        // with sign-in, the user preferences' table.
        $container->bind(\Preflow\Folio\Storage\TableSync::class, fn (Container $c) => new \Preflow\Folio\Storage\TableSync(
            $c->get(TypeCatalog::class),
            $c->get(TypeRegistry::class),
            $c->get(DataManager::class),
            $c->get(\Preflow\Publishing\PublishingTypes::class),
            [
                ...($extensions->all() !== [] ? [$c->get(\Preflow\Folio\Extension\ExtensionState::class)->definition()] : []),
                // With sign-in, the users' own settings (the interface language).
                ...($app->hasConfigFile('auth') ? [$c->get(\Preflow\Folio\Users\UserPreferences::class)->definition()] : []),
                // The admins' interface text overrides (Settings → Translations).
                $c->get(\Preflow\Folio\Translations\StoredOverrides::class)->definition(),
                // Redirect entries, the not-found list and the records' last public addresses.
                \Preflow\Folio\Redirects\RedirectEntries::definition(),
                \Preflow\Folio\Redirects\NotFoundEntries::definition(),
                \Preflow\Folio\Redirects\AddressTable::definition(),
                // The declared permissions the site has seen (new ones are announced on the Roles screen).
                \Preflow\Folio\Permissions\KnownPermissions::definition(),
            ],
        ));
        $container->get(\Preflow\Data\Schema\RecordTables::class)->add(
            'folio',
            static fn (): array => $container->get(\Preflow\Folio\Storage\TableSync::class)->own(),
        );
        $container->get(\Preflow\Data\Migration\MigrationRegistry::class)->afterMigrate(
            'folio.tables',
            static fn (): array => $container->get(\Preflow\Folio\Storage\TableSync::class)->syncOwn(),
        );
        // Which declared permissions are new since the site last looked (the Roles notice, badge and
        // dashboard line); migrate lists them once the table exists, and only where rbac's Roles screen
        // (the rbac_role type) is there to review and dismiss them: elsewhere the line would never go
        // away. The steps run after boot, when declarePermissions() has filled the registry.
        if ($dataManager !== null) {
            $container->singleton(\Preflow\Folio\Permissions\KnownPermissions::class, static fn (Container $c) => new \Preflow\Folio\Permissions\KnownPermissions(
                $c->get(DataManager::class),
                $c->get(\Preflow\Auth\Access\Access::class)->registry(),
            ));
            $container->get(\Preflow\Data\Migration\MigrationRegistry::class)->afterMigrate(
                'folio.permissions',
                static fn (): array => $container->get(TypeCatalog::class)->has('rbac_role')
                    ? $container->get(\Preflow\Folio\Permissions\KnownPermissions::class)->migrateLines()
                    : [],
            );
        }
        // Redirects and the not-found list (the routing core's RedirectMiddleware switches on with the
        // store). Built without touching the storage: a site not migrated yet fails inside the
        // middleware's lookups only, which keep the 404.
        if ($dataManager !== null) {
            $container->singleton(\Preflow\Folio\Redirects\RedirectEntries::class, static fn (Container $c) => new \Preflow\Folio\Redirects\RedirectEntries(
                $c->get(DataManager::class),
                static fn (): \Preflow\Routing\Redirect\RedirectRules => $c->get(\Preflow\Routing\Redirect\RedirectRules::class),
            ));
            $container->singleton(\Preflow\Folio\Redirects\NotFoundEntries::class, static fn (Container $c) => new \Preflow\Folio\Redirects\NotFoundEntries(
                $c->get(DataManager::class),
                \Preflow\Routing\Redirect\RedirectConfig::fromFile($app->basePath('config/redirects.php'))->notFoundLimit,
            ));
            $container->singleton(\Preflow\Folio\Redirects\LiveRecords::class, static fn (Container $c) => new \Preflow\Folio\Redirects\LiveRecords(
                $c->get(DataManager::class),
                $c->get(\Preflow\Publishing\Publisher::class),
                $c->get(FrontendResolver::class),
            ));
            $container->singleton(\Preflow\Folio\Redirects\FolioRedirectTargets::class, static fn (Container $c) => new \Preflow\Folio\Redirects\FolioRedirectTargets(
                $c->get(\Preflow\Folio\Redirects\LiveRecords::class),
                $c->get(FrontendResolver::class),
            ));
            // Where a redirect target really leads (a page's URL becomes its record; no chains), for
            // Settings → Redirects and the removal targets of deletes and unpublishes.
            $container->singleton(\Preflow\Folio\Redirects\SiteTarget::class, static fn (Container $c) => new \Preflow\Folio\Redirects\SiteTarget(
                $c->get(\Preflow\Folio\Redirects\RedirectEntries::class),
                static fn (): \Preflow\Folio\Redirects\LiveRecords => $c->get(\Preflow\Folio\Redirects\LiveRecords::class),
                $c->get(FrontendResolver::class),
                $locales ?? \Preflow\Data\Locales::single('en'),
                self::prefixedUrls($app),
                \Preflow\Folio\Redirects\SiteTarget::hostOf($app->config()->get('app.url')),
            ));
            // The write rules of a redirect entry (routing's), for every writer: the entries' save() and
            // Settings → Redirects. What only the site knows is answered by Folio's own classes.
            $container->singleton(\Preflow\Routing\Redirect\RedirectRules::class, static fn (Container $c) => new \Preflow\Routing\Redirect\RedirectRules(
                $c->get(\Preflow\Folio\Redirects\RedirectEntries::class),
                $c->get(\Preflow\Folio\Redirects\SiteTarget::class),
                $c->get(\Preflow\Folio\Redirects\LiveRecords::class),
                $c->get(\Preflow\Folio\Redirects\SiteTarget::class),
                $c->get(\Preflow\Folio\Redirects\NotFoundEntries::class),
            ));
            // The address book behind the automatic redirects. LiveRecords reads through the Publisher,
            // which writes through the address book: it is looked up when first needed.
            $container->singleton(\Preflow\Folio\Redirects\RecordAddresses::class, static fn (Container $c) => new \Preflow\Folio\Redirects\RecordAddresses(
                $c->get(DataManager::class),
                $c->get(\Preflow\Folio\Redirects\RedirectEntries::class),
                $c->get(FrontendResolver::class),
                static fn (): \Preflow\Folio\Redirects\LiveRecords => $c->get(\Preflow\Folio\Redirects\LiveRecords::class),
                self::prefixedUrls($app),
                $app->config()->get('app.debug', false) ? static fn (string $message) => error_log('folio: ' . $message) : null,
                $c->get(\Preflow\Folio\Redirects\NotFoundEntries::class),
                $c->get(\Preflow\Folio\Redirects\SiteTarget::class),
            ));
            // After the tables exist (folio.tables): every live page's current addresses, so the first
            // rename after an upgrade redirects for every type.
            $container->get(\Preflow\Data\Migration\MigrationRegistry::class)->afterMigrate(
                'folio.addresses',
                static fn (): array => $container->get(\Preflow\Folio\Redirects\RecordAddresses::class)->seed(),
            );
            $container->bind(\Preflow\Routing\Redirect\RedirectStore::class, static fn (Container $c) => $c->get(\Preflow\Folio\Redirects\RedirectEntries::class));
            $container->bind(\Preflow\Routing\Redirect\NotFoundLog::class, static fn (Container $c) => $c->get(\Preflow\Folio\Redirects\NotFoundEntries::class));
            // The redirect middleware is built at boot; the targets (the Publisher behind LiveRecords) only when a redirect points to a record.
            $container->bind(\Preflow\Routing\Redirect\RedirectTargets::class, static fn (Container $c) => new class ($c) implements \Preflow\Routing\Redirect\RedirectTargets {
                public function __construct(private readonly Container $container) {}

                public function url(string $type, string $id, string $locale): ?string
                {
                    return $this->container->get(\Preflow\Folio\Redirects\FolioRedirectTargets::class)->url($type, $id, $locale);
                }
            });
            // Settings → Redirects.
            $container->bind(\Preflow\Folio\Http\RedirectsController::class, static fn (Container $c) => new \Preflow\Folio\Http\RedirectsController(
                $c->get(\Preflow\Folio\Redirects\RedirectEntries::class),
                $c->get(\Preflow\Folio\Redirects\NotFoundEntries::class),
                $c->get(\Preflow\Folio\Redirects\LiveRecords::class),
                $c->get(FrontendResolver::class),
                $c->get(TypeCatalog::class),
                $c->get(\Preflow\Folio\Content\DisplayLabels::class),
                $c->bound(\Preflow\Core\Http\NonPublicPaths::class) ? $c->get(\Preflow\Core\Http\NonPublicPaths::class) : new \Preflow\Core\Http\NonPublicPaths(),
                $locales ?? \Preflow\Data\Locales::single('en'),
                self::prefixedUrls($app),
                $c->get(\Preflow\Folio\View\FolioEngine::class),
                $prefix,
                $c->get(\Preflow\Folio\Redirects\SiteTarget::class),
                $c->get(\Preflow\Routing\Redirect\RedirectRules::class),
            ));
        }
        $container->singleton(\Preflow\Folio\Extension\ExtensionState::class, fn (Container $c) => new \Preflow\Folio\Extension\ExtensionState(
            $c->get(DataManager::class),
        ));
        $container->bind(\Preflow\Folio\Http\ExtensionsController::class, fn (Container $c) => new \Preflow\Folio\Http\ExtensionsController(
            $c->get(ExtensionRegistry::class),
            $c->get(\Preflow\Folio\Extension\ExtensionState::class),
            new \Preflow\Folio\Extension\ExtensionsFile($app->basePath('config/folio/extensions.json'), $modelsPath),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            $c->get(TypeCatalog::class),
            $prefix,
            // Toggling on the screen: development by default; elsewhere the committed file is the source.
            (bool) ($folioCfg['extensions']['allow_admin_changes'] ?? $app->config()->get('app.debug', false)),
        ));

        // One EditableTexts for the screen and the AI button's units (LabelUnits): they list the same rows.
        $container->singleton(\Preflow\Folio\Translations\EditableTexts::class, fn (Container $c) => new \Preflow\Folio\Translations\EditableTexts(
            self::translator($c, $app),
            $c->bound(\Preflow\I18n\DerivedKeys::class) ? $c->get(\Preflow\I18n\DerivedKeys::class) : null,
            $locales ?? \Preflow\Data\Locales::single('en'),
        ));
        $container->bind(\Preflow\Folio\Translations\LabelUnits::class, fn (Container $c) => new \Preflow\Folio\Translations\LabelUnits(
            $c->get(\Preflow\Folio\Translations\EditableTexts::class),
            $c->get(\Preflow\Folio\Translations\StoredOverrides::class),
            $c->get(\Preflow\Folio\Http\InterfaceLocales::class),
        ));
        $container->bind(\Preflow\Folio\Http\TranslationsController::class, fn (Container $c) => new \Preflow\Folio\Http\TranslationsController(
            $c->get(\Preflow\Folio\Translations\StoredOverrides::class),
            $c->get(\Preflow\Folio\Translations\EditableTexts::class),
            $c->get(\Preflow\Folio\Http\InterfaceLocales::class),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            $prefix,
            $c->get(\Preflow\I18n\Machine\MachineTranslator::class),
            $c->get(\Preflow\Auth\Access\Access::class),
        ));
        $container->bind(\Preflow\Folio\Http\CacheController::class, fn (Container $c) => new \Preflow\Folio\Http\CacheController(
            $c->get(\Preflow\Core\Cache\CacheRegistry::class),
            $c->get(\Preflow\Core\Cache\OpcacheStatus::class),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            $prefix,
            // The page cache's refresh actions, when it is on (core's Refresher).
            $c->bound(\Preflow\Core\PageCache\Refresher::class) ? $c->get(\Preflow\Core\PageCache\Refresher::class) : null,
        ));
        // Settings → SEO audit: the report file, on every site (SQL or not); with seo.audit.run it also
        // runs the audit through view's Audits (core binds it where the audit is set up; built on use).
        $container->bind(\Preflow\Folio\Http\SeoAuditController::class, static fn (Container $c) => new \Preflow\Folio\Http\SeoAuditController(
            \Preflow\View\Audit\AuditReport::file($app->basePath()),
            $c->get(TypeCatalog::class),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            $prefix,
            static fn (string $permission): bool => $c->get(\Preflow\Auth\Access\Access::class)->can($permission),
            static fn (): ?\Preflow\View\Audit\Audits => $c->bound(\Preflow\View\Audit\Audits::class) ? $c->get(\Preflow\View\Audit\Audits::class) : null,
            self::logger($c),
        ));
        // The SEO audit's "where to fix" (boot() hands it to the audit's registry): built on the first finding.
        $container->singleton(\Preflow\Folio\Audit\AuditTargets::class, static fn (Container $c) => new \Preflow\Folio\Audit\AuditTargets(
            static fn (): FrontendResolver => $c->get(FrontendResolver::class),
            static fn (): string => $c->get(\Preflow\Media\MediaConfig::class)->url,
            ($locales ?? \Preflow\Data\Locales::single('en'))->default,
        ));

        // The media core's private files: Folio's rules (permission, login page) instead of its deny-all default.
        $container->singleton(\Preflow\Media\Access\PrivateFileAccess::class, fn (Container $c) => new \Preflow\Folio\Media\FolioPrivateFileAccess(
            $c->get(\Preflow\Folio\Content\FrontendGate::class),
            $assetsConfig['private_permission'],
            $assetsConfig['private_login'],
        ));
        $container->bind(\Preflow\Folio\Http\AssetLibraryController::class, fn (Container $c) => new \Preflow\Folio\Http\AssetLibraryController(
            $c->get(\Preflow\Media\MediaLibrary::class),
            $c->get(\Preflow\Media\Usage\AssetUsage::class),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            $c->get(TypeCatalog::class),
            $locales ?? \Preflow\Data\Locales::single('en'),
            $prefix,
            $c->get(FieldTypeRegistry::class),
            $c->get(\Preflow\Media\MediaConfig::class),
            $c->get(\Preflow\Media\Image\Formats::class),
            $c->get(\Preflow\Media\Cleanup\AssetCleanup::class),
            $c->get(\Preflow\I18n\Machine\MachineTranslator::class),
            $c->get(\Preflow\Auth\Access\Access::class),
        ));

        self::isolatePageCacheRenders($container);
        $container->bind(\Preflow\Folio\Http\WorkingCopyMiddleware::class,fn (Container $c) => new \Preflow\Folio\Http\WorkingCopyMiddleware(
            $c->get(\Preflow\Publishing\LiveContent::class),
            $c->get(\Preflow\Folio\Http\AdminNav::class),
        ));
        $navCollapsed = self::navCollapsed($folioCfg);
        // Built on first use (an admin request, the console): its Folio items come with it (adminNavItems()).
        $container->singleton(\Preflow\Folio\Http\AdminNav::class, function (Container $c) use ($prefix, $navCollapsed, $app): \Preflow\Folio\Http\AdminNav {
            $access = $c->get(\Preflow\Auth\Access\Access::class);

            $nav = new \Preflow\Folio\Http\AdminNav(
                $c->get(TypeCatalog::class),
                static fn (string $permission): bool => $access->can($permission),
                $prefix,
                $c->get(\Preflow\Folio\Content\DisplayLabels::class),
            );
            // One instance serves every request: the person is looked up when the sidebar renders.
            // No person (no auth, or signed out): null, every section renders open (nothing can be saved).
            $nav->setOpenState(static function () use ($c): ?array {
                if (!$c->bound(\Preflow\Auth\AuthManager::class)) {
                    return null;
                }
                $user = $c->get(\Preflow\Auth\AuthManager::class)->user();

                return $user !== null ? $c->get(\Preflow\Folio\Users\UserPreferences::class)->navSections($user->getAuthId()) : null;
            }, $navCollapsed);
            $this->adminNavItems($nav, $c, $app);

            return $nav;
        });
        $container->bind(ActionResolver::class, fn (Container $c) => new ActionResolver($c));
        // Routes are computed (and validated) now, so conflicting "frontend"
        // declarations fail at boot (or at `preflow optimize`, which keeps them in the boot
        // manifest like the catalog they come from); the resolver itself is built lazily.
        $frontendRoutes = array_map(
            static fn (array $route): \Preflow\Folio\Content\FrontendRoute => new \Preflow\Folio\Content\FrontendRoute(...$route),
            $app->bootManifest()->remember(
                'folio.frontend_routes',
                static fn (): array => array_map(
                    static fn (\Preflow\Folio\Content\FrontendRoute $route): array => get_object_vars($route),
                    FrontendResolver::routesFromCatalog($container->get(TypeCatalog::class)),
                ),
                [$modelsPath],
            ),
        );
        $container->bind(FrontendResolver::class, fn (Container $c) => new FrontendResolver(
            $c->get(DataManager::class),
            $frontendRoutes,
            $c->has(\Preflow\Core\PageCache\PageCache::class) ? $c->get(\Preflow\Core\PageCache\PageCache::class) : null,
        ));

        $uploadsDir = $this->uploadsDir($app);
        $uploadUrlPrefix = rtrim($this->prefix($app), '/') . '/_uploads';
        $folioConfig = $this->folioConfig($app);
        $container->singleton(FieldTypeRegistry::class, function (Container $c) use ($uploadsDir, $uploadUrlPrefix, $prefix, $folioConfig): FieldTypeRegistry {
            $registry = new FieldTypeRegistry();
            $registry->register(new StringFieldType());
            $registry->register(new TextFieldType());
            $registry->register(new NumberFieldType());
            $registry->register(new BooleanFieldType());
            $registry->register(new DateFieldType());
            $registry->register(new SelectFieldType());
            $registry->register(new EnvFieldType());
            $registry->register(new BlocksFieldType(
                $c->get(BlockRegistry::class),
                $registry,
                $c->get(\Preflow\Blocks\BlocksConfig::class)->maxDepth,
                self::validatorFactory($c),
                $c->get(\Preflow\Folio\View\FolioEngine::class),
                $prefix,
            ));
            // The sanitizer is built on the first sanitize call, not on every request.
            $registry->register(new RichTextFieldType(\Preflow\Folio\Field\LazyHtmlSanitizer::standard()));
            // Editors render only the controls the user may use (the routes check again).
            $can = static fn (string $permission): bool => $c->get(\Preflow\Auth\Access\Access::class)->can($permission);
            $registry->register(new AssetFieldType(
                $uploadsDir,
                $uploadUrlPrefix,
                $c->get(\Preflow\Media\ImageTag::class),
                $c->get(\Preflow\Media\MediaLibrary::class),
                $prefix,
                $can,
            ));
            $registry->register(new RelationFieldType(
                $c->get(\Preflow\Data\DataManager::class),
                $c->get(TypeRegistry::class),
                $c->get(ContentLocale::class),
                $c->get(\Preflow\Publishing\LiveContent::class),
                $can,
                $prefix,
            ));
            $registry->register(new MatrixFieldType(
                $c->get(TypeCatalog::class),
                $c->get(TypeRegistry::class),
                $c->get(\Preflow\Data\DataManager::class),
                new RecordRenderer($registry, $c->get(\Preflow\Folio\View\FolioEngine::class), $c->get(ContentLocale::class)),
                new RecordLabeler(),
                $prefix,
                $c->get(\Preflow\Publishing\LiveContent::class),
                $can,
            ));
            $registry->alias('int', 'number');
            $registry->alias('integer', 'number');
            $registry->alias('float', 'number');

            // Field types of installed packages (composer.json extra.folio.field_types), then the app's
            // (config/folio.php 'field_types'): built by the container; a later one with a key already
            // taken replaces it, so the app's win over the packages', and both over the built-ins.
            foreach (self::packageClasses($c->get(Application::class), 'folio.field_types', FieldType::class) as $class) {
                $registry->register($c->get($class));
            }
            foreach ((array) ($folioConfig['field_types'] ?? []) as $class) {
                $registry->register($c->get($class));
            }

            return $registry;
        });

        $container->singleton(\Preflow\Folio\Content\RecordValidator::class, fn (Container $c) => new \Preflow\Folio\Content\RecordValidator(
            $c->get(FieldTypeRegistry::class),
            $c->get(DataManager::class),
            $c->get(FrontendResolver::class),
        ));
        $this->registerPublishing($container);
        $container->singleton(\Preflow\Folio\Content\ReviewIndexUpdater::class, fn (Container $c) => new \Preflow\Folio\Content\ReviewIndexUpdater(
            $c->get(\Preflow\I18n\Machine\ReviewIndex::class),
            $c->get(\Preflow\Folio\Content\TranslationStatus::class),
            (new RecordLabeler())->label(...),
        ));
        // A member's own deletion (preflow/members) counts pointers in published snapshots, as a staff delete does.
        if (interface_exists(\Preflow\Members\MemberDeleteCopies::class)) {
            $container->singleton(\Preflow\Members\MemberDeleteCopies::class, static fn (Container $c): \Preflow\Members\MemberDeleteCopies => new \Preflow\Folio\Members\FolioMemberDeleteCopies(
                $c->get(\Preflow\Publishing\PublishedCopies::class),
            ));
        }
        $container->singleton(\Preflow\Folio\Content\TranslationStatus::class, fn (Container $c) => new \Preflow\Folio\Content\TranslationStatus(
            $c->get(FieldTypeRegistry::class),
            $c->get(\Preflow\Folio\Content\DisplayLabels::class),
        ));
        $container->bind(DraftRecordFactory::class, fn (Container $c) => new DraftRecordFactory(
            $c->get(FieldTypeRegistry::class),
            $c->get(\Preflow\Folio\Content\TranslationStatus::class),
        ));
        $container->bind(AdminController::class, fn (Container $c) => new AdminController(
            $c->get(TypeCatalog::class),
            $c->get(TypeRegistry::class),
            $c->get(DataManager::class),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            $c->get(ActionResolver::class),
            $c->get(FieldTypeRegistry::class),
            $prefix,
            new RecordLabeler(),
            $c->get(FrontendResolver::class),
            $c->get(\Preflow\Publishing\RecordWriter::class),
            $c->get(\Preflow\Publishing\RecordDeleter::class),
            $c->get(DraftRecordFactory::class),
            $c->get(\Preflow\Publishing\Publisher::class),
            $c->get(\Preflow\Publishing\PreviewLinks::class),
            $c->get(\Preflow\Auth\Access\Access::class),
            $c->get(Customizations::class),
            new \Preflow\Folio\Users\AuthorNames($c->get(DataManager::class), static fn (): array => self::recordProviders($c), static fn (): ?\Preflow\Auth\UserProviderInterface => self::staffProviderOrNone($c)),
            pageAssets: $c->get(\Preflow\Folio\Extension\Screen\PageAssets::class),
            labels: $c->get(\Preflow\Folio\Content\DisplayLabels::class),
            translations: $c->get(\Preflow\Folio\Content\TranslationStatus::class),
            translator: $c->get(\Preflow\I18n\Machine\MachineTranslator::class),
            jobs: $c->get(\Preflow\I18n\Machine\JobRunner::class),
            reviewIndex: $c->get(\Preflow\I18n\Machine\ReviewIndex::class),
            worker: $c->get(\Preflow\I18n\Machine\TranslationWorker::class),
            seo: $c->bound(\Preflow\View\Seo\SeoConfig::class) ? $c->get(\Preflow\View\Seo\SeoConfig::class) : null,
            addresses: self::addresses($c),
            oldAddresses: $c->bound(\Preflow\Folio\Redirects\RedirectEntries::class) ? new \Preflow\Folio\Redirects\OldAddresses(
                $c->get(\Preflow\Folio\Redirects\RedirectEntries::class),
                new \Preflow\Folio\Redirects\VisibleAddress(($locales ?? \Preflow\Data\Locales::single('en'))->default, self::prefixedUrls($app)),
                $app->config()->get('app.debug', false) ? static fn (string $message) => error_log('folio: ' . $message) : null,
            ) : null,
            pageMeta: $c->bound(\Preflow\Folio\Seo\FolioPageMeta::class) ? $c->get(\Preflow\Folio\Seo\FolioPageMeta::class) : null,
            siteUrl: is_string($appUrl = $app->config()->get('app.url')) && $appUrl !== '' ? $appUrl : null,
        ));
        $container->bind(\Preflow\Folio\Http\AiTranslateController::class, fn (Container $c) => new \Preflow\Folio\Http\AiTranslateController(
            $c->get(TypeCatalog::class),
            $c->get(TypeRegistry::class),
            $c->get(DataManager::class),
            $c->get(\Preflow\I18n\Machine\TextSources::class),
            $c->get(\Preflow\I18n\Machine\MachineTranslator::class),
            $c->get(\Preflow\I18n\Machine\Estimator::class),
            $c->get(\Preflow\I18n\Machine\JobRunner::class),
            $c->get(\Preflow\I18n\Machine\JobStore::class),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            $prefix,
            $c->get(\Preflow\Auth\Access\Access::class),
            $c->get(\Preflow\Folio\Translations\LabelUnits::class),
            $c->get(\Preflow\Media\MediaLibrary::class),
            $locales ?? \Preflow\Data\Locales::single('en'),
            $c->get(\Preflow\I18n\Machine\TranslationWorker::class),
            new \Preflow\Folio\Users\AuthorNames($c->get(DataManager::class), static fn (): array => self::recordProviders($c), static fn (): ?\Preflow\Auth\UserProviderInterface => self::staffProviderOrNone($c)),
        ));
        $container->bind(\Preflow\Folio\Http\AiSettingsController::class, fn (Container $c) => new \Preflow\Folio\Http\AiSettingsController(
            $c->get(\Preflow\Ai\Ai::class),
            $c->get(\Preflow\Ai\Usage\UsageLog::class),
            $c->get(\Preflow\I18n\Machine\TranslationSettings::class),
            $c->get(TypeCatalog::class),
            $c->get(TypeRegistry::class),
            $c->get(ContentLocale::class),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            $prefix,
        ));
        $container->bind(\Preflow\Folio\Http\PublishingController::class, fn (Container $c) => new \Preflow\Folio\Http\PublishingController(
            $c->get(TypeCatalog::class),
            $c->get(\Preflow\Publishing\Publisher::class),
            $prefix,
            $c->get(DataManager::class),
            $c->get(DraftRecordFactory::class),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            $c->get(\Preflow\Publishing\RecordWriter::class),
            $c->get(\Preflow\Publishing\PreviewLinks::class),
            $c->get(FrontendResolver::class),
            $c->get(\Preflow\Auth\Access\Access::class),
        ));
        $container->bind(FrontendController::class, fn (Container $c) => new FrontendController(
            $c->get(FrontendResolver::class),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            new RecordRenderer($c->get(FieldTypeRegistry::class), $c->get(\Preflow\Folio\View\FolioEngine::class), $c->get(ContentLocale::class)),
            $c->get(ContentLocale::class),
            $c->get(DataManager::class),
            $c->get(\Preflow\Publishing\PreviewLinks::class),
            $c->get(\Preflow\Publishing\LiveContent::class),
            $c->get(\Preflow\View\AssetCollector::class),
            $c->get(\Preflow\Folio\Content\FrontendGate::class),
            $c->get(\Preflow\Core\Error\ErrorHandler::class),
            is_string($appUrl = $app->config()->get('app.url')) ? $appUrl : null,
            $c->bound(\Preflow\Folio\Seo\FolioPageMeta::class) ? $c->get(\Preflow\Folio\Seo\FolioPageMeta::class) : null,
            $c->bound(\Preflow\Folio\Seo\FolioPageMeta::class) ? $c->get(\Preflow\View\Seo\SeoContext::class) : null,
        ));
        $container->singleton(\Preflow\Folio\Content\FrontendGate::class, fn (Container $c) => new \Preflow\Folio\Content\FrontendGate(
            $c->get(\Preflow\Auth\Access\Access::class),
            (bool) $app->config()->get('app.debug', false),
        ));
        // Folio's admin assets; packages add their field types' files (AdminAssets::add()).
        $container->singleton(\Preflow\Folio\Http\AdminAssets::class, fn () => new \Preflow\Folio\Http\AdminAssets($this->assetMap()));
        $container->singleton(\Preflow\Folio\Http\AdminAssetUrls::class, fn (Container $c) => new \Preflow\Folio\Http\AdminAssetUrls(
            rtrim($this->prefix($app), '/'),
            dirname(__DIR__) . '/assets',
            $c->get(\Preflow\Folio\Http\AdminAssets::class),
        ));
        $container->bind(PreviewController::class, fn (Container $c) => new PreviewController(
            $c->get(TypeCatalog::class),
            $c->get(TypeRegistry::class),
            $c->get(DataManager::class),
            $c->get(FieldTypeRegistry::class),
            new RecordRenderer($c->get(FieldTypeRegistry::class), $c->get(\Preflow\Folio\View\FolioEngine::class), $c->get(ContentLocale::class)),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            $c->get(FrontendResolver::class),
            $c->get(DraftRecordFactory::class),
            $c->get(ContentLocale::class),
            $c->get(\Preflow\View\AssetCollector::class),
            $c->get(\Preflow\Folio\Http\AdminAssetUrls::class),
            self::translator($c, $app),
        ));
        $container->bind(AssetController::class, fn (Container $c) => new AssetController(
            dirname(__DIR__) . '/assets',
            $c->get(\Preflow\Folio\Http\AdminAssets::class),
        ));
        // Enabled extensions' assets/ files at {prefix}/_assets/x/{extension}/… (public).
        $container->singleton(\Preflow\Folio\Extension\ExtensionAssets::class, fn (Container $c) => new \Preflow\Folio\Extension\ExtensionAssets(
            $c->get(ExtensionRegistry::class),
            $this->prefix($app),
        ));
        $container->bind(\Preflow\Folio\Http\ExtensionAssetController::class, fn (Container $c) => new \Preflow\Folio\Http\ExtensionAssetController(
            $c->get(\Preflow\Folio\Extension\ExtensionAssets::class),
        ));
        $container->singleton(\Preflow\Folio\Content\Singletons::class, fn (Container $c) => new \Preflow\Folio\Content\Singletons(
            $c->get(DataManager::class),
            $c->get(TypeCatalog::class),
            $c->get(ContentLocale::class),
            $c->get(\Preflow\Publishing\LiveContent::class),
            $c->get(\Preflow\Folio\Content\SafeRichText::class),
        ));
        $container->singleton(ExtensionSettings::class, fn (Container $c) => new ExtensionSettings(
            $c->get(ExtensionRegistry::class),
            $c->get(TypeCatalog::class),
            $c->get(TypeRegistry::class),
            $c->get(\Preflow\Folio\Content\Singletons::class),
            $c->get(FieldTypeRegistry::class),
            $c->get(ContentLocale::class),
            (bool) $app->config()->get('app.debug', false),
        ));
        $container->singleton(\Preflow\Folio\Content\Collections::class, fn (Container $c) => new \Preflow\Folio\Content\Collections(
            $c->get(\Preflow\Publishing\LiveContent::class),
            $c->get(TypeCatalog::class),
            $c->get(ContentLocale::class),
            $c->get(\Preflow\Folio\Content\SafeRichText::class),
            $c->get(\Preflow\Folio\Content\FrontendGate::class),
        ));
        $container->singleton(\Preflow\Folio\Content\SafeRichText::class, fn (Container $c) => new \Preflow\Folio\Content\SafeRichText(
            $c->get(FieldTypeRegistry::class),
            $c->get(BlockRegistry::class),
        ));
        // Blocks get their rich text sanitized on the way to their templates.
        if (!$container->bound(\Preflow\Blocks\BlockTextCleaner::class)) {
            $container->singleton(\Preflow\Blocks\BlockTextCleaner::class, static fn (Container $c): \Preflow\Blocks\BlockTextCleaner => $c->get(\Preflow\Folio\Content\SafeRichText::class));
        }
        $container->bind(\Preflow\Folio\Http\BlockEditorController::class, fn (Container $c) => new \Preflow\Folio\Http\BlockEditorController(
            $c->get(FieldTypeRegistry::class),
            $locales ?? \Preflow\Data\Locales::single('en'),
            $c->get(DataManager::class),
            $c->get(TypeCatalog::class),
        ));
        $container->bind(UploadController::class, fn (Container $c) => new UploadController($uploadsDir));

        // The engine Folio renders with: its Twig templates (*.twig) through a Twig engine of its own,
        // whatever app.engine is, built like the app's (TemplateEngineContext) with the app engine's
        // functions; app templates through the app's engine. On a Twig app both are the app's engine.
        $container->singleton(\Preflow\Folio\View\FolioEngine::class, static fn (Container $c): \Preflow\Folio\View\FolioEngine => \Preflow\Folio\View\FolioEngine::for(
            $c->get(TemplateEngineInterface::class),
            static function () use ($c): TemplateEngineInterface {
                $context = $c->get(\Preflow\View\TemplateEngineContext::class);

                return new \Preflow\Twig\TwigEngine(
                    templateDirs: $context->templateDirs,
                    assetCollector: $context->assets,
                    debug: $context->debug,
                    cachePath: rtrim($context->cachePath, '/') . '/folio-twig',
                    geoCollector: $context->geo,
                    seoContext: $context->seo,
                    liveParts: $context->liveParts,
                    pageCache: $context->pageCache,
                );
            },
        ));
        // The guard staff sign in through: config/folio.php 'guard', else the default guard (checked in boot()).
        $container->singleton(\Preflow\Folio\Users\StaffGuard::class, static fn (): \Preflow\Folio\Users\StaffGuard => \Preflow\Folio\Users\StaffGuard::fromConfig(
            $folioCfg,
            is_array($auth = $app->configFile('auth')) ? $auth : null,
        ));
        // The staff guard's user provider, for Folio and the packages that manage staff (preflow/rbac).
        $container->singleton(\Preflow\Folio\Users\StaffUsers::class, static fn (Container $c): \Preflow\Folio\Users\StaffUsers => new \Preflow\Folio\Users\StaffUsers(
            $c->get(\Preflow\Folio\Users\StaffGuard::class),
            static fn (): ?\Preflow\Auth\AuthManager => $c->bound(\Preflow\Auth\AuthManager::class) ? $c->get(\Preflow\Auth\AuthManager::class) : null,
            static function () use ($c): ?\Preflow\Auth\UserProviderInterface {
                try {
                    return $c->get(\Preflow\Auth\UserProviderInterface::class);
                } catch (\Throwable) { // no config/auth.php: nothing bound
                    return null;
                }
            },
        ));
        // The password links of the staff guard's record accounts: one for the app, used by
        // StaffPasswords here and by preflow/rbac's StaffAccounts.
        $container->singleton(\Preflow\Auth\Record\AccountLinks::class, static fn (Container $c): \Preflow\Auth\Record\AccountLinks => new \Preflow\Auth\Record\AccountLinks(
            $c->get(\Preflow\Auth\Tokens\Tokens::class),
            $c->has(\Preflow\Auth\Remember\RememberMe::class) ? $c->get(\Preflow\Auth\Remember\RememberMe::class) : null,
            $c->get(\Preflow\Auth\PasswordHasherInterface::class),
            $c->get(DataManager::class),
            self::staffProvider($c),
            self::recordProviders($c),
            $c->get(\Preflow\Folio\Users\StaffGuard::class)->name,
        ));
        // preflow/rbac's staff accounts sign in through Folio's staff guard; their invite and reset
        // links open Folio's set-password page, their mails are in the user's interface language.
        if (class_exists(\Preflow\Rbac\Staff\StaffPopulation::class)) {
            $container->singleton(\Preflow\Rbac\Staff\StaffPopulation::class, static fn (Container $c): \Preflow\Rbac\Staff\StaffPopulation => new \Preflow\Rbac\Staff\StaffPopulation(
                $c->get(\Preflow\Folio\Users\StaffGuard::class)->name,
            ));
            $container->singleton(\Preflow\Folio\Rbac\FolioStaffLinks::class, static fn (Container $c): \Preflow\Folio\Rbac\FolioStaffLinks => new \Preflow\Folio\Rbac\FolioStaffLinks(
                self::passwordUrl($c->get(Application::class), $prefix),
                $c->get(\Preflow\Folio\Users\UserPreferences::class),
            ));
            $container->singleton(\Preflow\Rbac\Staff\StaffLinks::class, static fn (Container $c): object => $c->get(\Preflow\Folio\Rbac\FolioStaffLinks::class));
            $container->singleton(\Preflow\Rbac\Staff\StaffLocales::class, static fn (Container $c): object => $c->get(\Preflow\Folio\Rbac\FolioStaffLinks::class));
        }
        $container->bind(AdminAuthMiddleware::class, fn (Container $c) => new AdminAuthMiddleware(
            $c->get(\Preflow\Auth\AuthManager::class),
            $prefix,
            $c->get(\Preflow\Folio\Http\AdminNav::class),
            $c->get(\Preflow\Folio\Users\StaffGuard::class)->name,
        ));
        // Each user's interface language (UserPreferences), applied per admin request.
        $container->singleton(\Preflow\Folio\Users\UserPreferences::class, fn (Container $c) => new \Preflow\Folio\Users\UserPreferences(
            $c->get(DataManager::class),
        ));
        $container->singleton(\Preflow\Folio\Http\InterfaceLocales::class, function (Container $c) use ($app, $folioCfg): \Preflow\Folio\Http\InterfaceLocales {
            $translator = self::translator($c, $app);
            // The languages with Folio texts (lang files of the packages and the site): from the boot manifest when it has them.
            $locales = $app->bootManifest()->remember(
                'folio.interface_locales',
                static fn (): array => (new \Preflow\Folio\Http\InterfaceLocales($translator))->all(),
                [$app->basePath('lang')],
            );

            return new \Preflow\Folio\Http\InterfaceLocales($translator, self::interfaceDefault($app, $folioCfg), $locales);
        });
        $container->bind(\Preflow\Folio\Http\AdminLocaleMiddleware::class, fn (Container $c) => new \Preflow\Folio\Http\AdminLocaleMiddleware(
            self::translator($c, $app),
            $c->get(\Preflow\Folio\Http\InterfaceLocales::class),
            $c->get(\Preflow\Folio\Users\UserPreferences::class),
            $c->has(TemplateEngineInterface::class) ? $c->get(\Preflow\Folio\View\FolioEngine::class) : null,
            static fn () => $c->get(\Preflow\Folio\Translations\OverridesCache::class)->startRequest(),
            self::validatorFactory($c),
        ));
        $container->bind(\Preflow\Folio\Http\LocaleController::class, fn (Container $c) => new \Preflow\Folio\Http\LocaleController(
            $c->get(\Preflow\Folio\Users\UserPreferences::class),
            $c->get(\Preflow\Folio\Http\InterfaceLocales::class),
            self::translator($c, $app),
            $prefix,
        ));
        $container->bind(\Preflow\Folio\Http\NavPreferenceController::class, fn (Container $c) => new \Preflow\Folio\Http\NavPreferenceController(
            $c->get(\Preflow\Folio\Users\UserPreferences::class),
            $prefix,
        ));
        $container->bind(\Preflow\Folio\Http\FolioPermissionMiddleware::class, fn (Container $c) => new \Preflow\Folio\Http\FolioPermissionMiddleware(
            $c->get(\Preflow\Auth\Access\Access::class),
            $c->get(TypeCatalog::class),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            $prefix,
            $c->get(ScreenRegistry::class),
        ));
        // Extension screens: validated when first resolved (the admin setup, ensureAdmin()), so collisions fail there.
        $container->singleton(ScreenRegistry::class, fn (Container $c) => ScreenRegistry::fromExtensions(
            $c->get(ExtensionRegistry::class),
            $c->get(TypeCatalog::class),
        ));
        // Type customizations: validated when first resolved (the admin setup, ensureAdmin()); their classes come from the container.
        $container->singleton(Customizations::class, fn (Container $c) => Customizations::fromExtensions(
            $c->get(ExtensionRegistry::class),
            $c->get(TypeCatalog::class),
            static fn (string $class): object => $c->get($class),
            $c->get(TypeRegistry::class),
        ));
        $container->bind(\Preflow\Folio\Extension\Screen\ScreenDispatcher::class, fn (Container $c) => new \Preflow\Folio\Extension\Screen\ScreenDispatcher(
            $c,
            $c->get(ScreenRegistry::class),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            $prefix,
            $c->get(\Preflow\Folio\Extension\Screen\PageAssets::class),
        ));
        $container->singleton(\Preflow\Folio\Extension\Screen\PageAssets::class, fn (Container $c) => new \Preflow\Folio\Extension\Screen\PageAssets(
            $c->get(\Preflow\Folio\Extension\ExtensionAssets::class),
            $c->get(\Preflow\Folio\Http\AdminAssets::class),
            $c->get(\Preflow\Folio\Http\AdminAssetUrls::class),
        ));
        $container->bind(AuthController::class, function (Container $c) use ($prefix): AuthController {
            // Record users behind the guard: the throttled login with remember-me and the password pages.
            $passwords = self::recordProviders($c) !== [] ? $c->get(\Preflow\Folio\Users\StaffPasswords::class) : null;

            return new AuthController(
                $c->get(\Preflow\Auth\AuthManager::class),
                self::staffProvider($c),
                $c->get(\Preflow\Folio\View\FolioEngine::class),
                $prefix,
                $passwords,
                $passwords === null ? null : $c->get(\Preflow\Core\Throttle\Throttle::class),
                $passwords === null ? null : $c->get(\Preflow\Auth\PasswordHasherInterface::class),
                $c->get(\Preflow\Folio\Users\StaffGuard::class)->name,
            );
        });
        $container->singleton(\Preflow\Folio\Users\StaffPasswords::class, fn (Container $c) => self::staffPasswords($c, $prefix));
        $container->bind(\Preflow\Folio\Http\AccountController::class, fn (Container $c) => new \Preflow\Folio\Http\AccountController(
            $c->get(\Preflow\Folio\Users\StaffPasswords::class),
            $c->get(\Preflow\Core\Throttle\Throttle::class),
            $c->get(\Preflow\Auth\AuthManager::class),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            $c->get(TypeCatalog::class),
            $prefix,
            max(1, (int) ($this->folioConfig($app)['password_min'] ?? 10)),
            $c->get(\Preflow\Folio\Users\StaffGuard::class)->name,
        ));
        $container->bind(\Preflow\Folio\Http\PasswordController::class, fn (Container $c) => new \Preflow\Folio\Http\PasswordController(
            $c->get(\Preflow\Folio\Users\StaffPasswords::class),
            $c->get(\Preflow\Core\Throttle\Throttle::class),
            $c->get(\Preflow\Auth\AuthManager::class),
            $c->get(\Preflow\Folio\View\FolioEngine::class),
            $prefix,
            max(1, (int) ($this->folioConfig($app)['password_min'] ?? 10)),
            $c->get(\Preflow\Folio\Users\StaffGuard::class)->name,
        ));
    }

    /**
     * The published site for every render that isn't the admin's own: what the page cache may
     * store (a visitor's miss, verify's second render, a rebuild behind an admin request) and
     * every internal render (RenderScopes: the SEO audit's pages, also a step run from Folio, and
     * GEO's Markdown views) read published snapshots with empty memos, whatever the request around
     * it switched on (WorkingCopyMiddleware on every admin route, a share preview). A page the
     * page cache would store while working copies show anyway is refused (`refused:working-copy`).
     */
    private static function isolatePageCacheRenders(Container $container): void
    {
        $published = self::publishedReads($container);
        if ($container->bound(\Preflow\View\Geo\RenderScopes::class)) {
            $container->get(\Preflow\View\Geo\RenderScopes::class)->add(
                static function (\Psr\Http\Message\ServerRequestInterface $sub, \Closure $next) use ($published): \Psr\Http\Message\ResponseInterface {
                    $restore = $published();
                    try {
                        return $next();
                    } finally {
                        $restore();
                    }
                },
            );
        }
        if (!$container->bound(\Preflow\Core\PageCache\PageCache::class)) {
            return;
        }
        $pageCache = $container->get(\Preflow\Core\PageCache\PageCache::class);
        $pageCache->isolate($published);
        $pageCache->refuseWhen('working-copy', static fn (): bool => $container->resolved(\Preflow\Publishing\LiveContent::class)
            && $container->get(\Preflow\Publishing\LiveContent::class)->showsWorkingCopies());
    }

    /**
     * Switches to the published site for one render: LiveContent reads published snapshots, the
     * Collections and Singletons memos start empty. Answers the function that puts it all back.
     *
     * @return \Closure(): (\Closure(): void)
     */
    private static function publishedReads(Container $container): \Closure
    {
        return static function () use ($container): \Closure {
            $restores = [$container->get(\Preflow\Publishing\LiveContent::class)->isolate()];
            foreach ([\Preflow\Folio\Content\Collections::class, \Preflow\Folio\Content\Singletons::class] as $memo) {
                // One not built yet starts empty; one built during the render is emptied after it.
                $restores[] = $container->resolved($memo)
                    ? $container->get($memo)->isolate()
                    : static function () use ($container, $memo): void {
                        if ($container->resolved($memo)) {
                            $container->get($memo)->isolate();
                        }
                    };
            }

            return static function () use ($restores): void {
                foreach (array_reverse($restores) as $restore) {
                    $restore();
                }
            };
        };
    }

    /**
     * The record providers (Folio user types) behind the staff guard; none without auth.
     *
     * @return list<\Preflow\Auth\Record\RecordUserProvider>
     */
    private static function recordProviders(Container $c): array
    {
        try {
            $users = self::staffProvider($c);
        } catch (\Throwable) { // no config/auth.php: nothing bound
            return [];
        }

        return \Preflow\Folio\Users\RecordProviders::of($users);
    }

    /** The staff guard's user provider (StaffUsers); null without auth. */
    private static function staffProviderOrNone(Container $c): ?\Preflow\Auth\UserProviderInterface
    {
        return $c->get(\Preflow\Folio\Users\StaffUsers::class)->provider();
    }

    /** The staff guard's user provider; throws without auth (as the UserProviderInterface binding did). */
    private static function staffProvider(Container $c): \Preflow\Auth\UserProviderInterface
    {
        return self::staffProviderOrNone($c) ?? throw new \RuntimeException('Folio has no staff guard: config/auth.php is missing.');
    }

    private static function staffPasswords(Container $c, string $prefix): \Preflow\Folio\Users\StaffPasswords
    {
        $app = $c->get(Application::class);

        return new \Preflow\Folio\Users\StaffPasswords(
            $c->get(\Preflow\Auth\Record\AccountLinks::class),
            $c->get(DataManager::class),
            static function () use ($c, $app): \Preflow\Folio\Users\StaffMailer {
                return new \Preflow\Folio\Users\StaffMailer(
                    $c->get(\Preflow\Notify\Mail\Mailer::class),
                    self::translator($c, $app),
                    $c->get(\Preflow\Data\Locales::class)->default, // for recipients without an interface language
                    \Preflow\Notify\Mail\MailText::siteName($app->config()->get('app.name'), $app->config()->get('app.url')),
                    // One source for staff mails: rbac's, where it is installed (bound by its provider).
                    $c->bound(\Preflow\Rbac\Staff\StaffMail::class) ? $c->get(\Preflow\Rbac\Staff\StaffMail::class) : null,
                );
            },
            self::passwordUrl($app, $prefix),
            $c->get(\Preflow\Folio\Users\UserPreferences::class),
        );
    }

    /** The absolute URL of the set-password page; '' when app.url is unset (no links are mailed: StaffPasswords::canMail). */
    private static function passwordUrl(Application $app, string $prefix): string
    {
        $url = $app->config()->get('app.url');
        $base = is_string($url) ? rtrim(trim($url), '/') : '';

        return $base === '' ? '' : $base . $prefix . '/password';
    }

    /**
     * The app's translator with Folio's texts. A core before 0.37 boots none without lang/ or
     * config/i18n.php: then one is made and bound here.
     */
    /**
     * The keys of the model files' labels for translations:check (preflow/i18n DerivedKeys):
     * the site's model files in "site", enabled extensions' models (as their files are, before
     * prepareModel) and settings forms in the extension's group, plus {group}.sections.{name} for a sidebar
     * section that isn't Folio's own.
     *
     * @param list<array{0: string, 1: array<string, mixed>, 2: string}> $extensionModels what, schema, group
     */
    /**
     * The types enabled extensions define: their model schemas (model(), prepareModel(), the
     * site's "extends" files merged in) and settings types, each [schema, section, what, label
     * group]; and the label sources for registerLabelKeys(). A site "extends" file no enabled
     * extension type takes is an error.
     *
     * @return array{0: list<array{0: array<string, mixed>, 1: string, 2: string, 3: string}>, 1: list<array{0: string, 1: array<string, mixed>, 2: string}>}
     */
    private function extensionTypes(Container $container, ExtensionRegistry $extensions, string $modelsPath): array
    {
        $extensionTypes = [];
        $labelSources = [];
        foreach ($extensions->enabled() as $definition) {
            foreach ($extensions->enabledModels($definition->name) as $schema) {
                $extension = $container->get($definition->class);
                $schema = $extension->model($schema);
                $labelSources[] = ['extension ' . $definition->name . ' model ' . $schema['key'], $schema, $definition->labelGroup()];
                $extensionTypes[] = [$extension->prepareModel($schema, $container), 'content', 'Extension model', $definition->labelGroup()];
            }
        }
        foreach ($extensions->enabled() as $definition) {
            if ($extensions->partEnabled($definition->name, 'settings')) {
                // The settings form's labels are the extension's to deliver: in its group, like its models'.
                $schema = ExtensionSettings::schema($definition, $container->get($definition->class)->settings());
                if ($definition->labelGroup() !== 'site') {
                    $labelSources[] = ['extension ' . $definition->name . ' settings', $schema, $definition->labelGroup()];
                }
                $extensionTypes[] = [$schema, 'settings', 'Extension settings type', $definition->labelGroup()];
            }
        }
        // Site model files with "extends": true add fields to these types (ModelExtension).
        $patches = ModelExtension::patches($modelsPath);
        $patchFiles = $patches === [] ? [] : ModelExtension::files($modelsPath);
        foreach ($extensionTypes as $i => [$schema]) {
            if (isset($patches[$schema['key']])) {
                $extensionTypes[$i][0] = ModelExtension::merge($schema, $patches[$schema['key']], $patchFiles[$schema['key']] ?? '');
                unset($patches[$schema['key']]);
            }
        }
        if ($patches !== []) {
            throw ModelExtension::orphanError((string) array_key_first($patches), $modelsPath, $extensions);
        }

        return [$extensionTypes, $labelSources];
    }

    private function registerLabelKeys(Container $container, string $modelsPath, array $extensionModels, TypeRegistry $types, TypeCatalog $catalog): void
    {
        if (!class_exists(\Preflow\I18n\DerivedKeys::class)) {
            return;
        }
        if (!$container->bound(\Preflow\I18n\DerivedKeys::class)) {
            $container->instance(\Preflow\I18n\DerivedKeys::class, new \Preflow\I18n\DerivedKeys());
        }
        $container->get(\Preflow\I18n\DerivedKeys::class)->add('Folio model labels', static function () use ($modelsPath, $extensionModels, $types, $catalog): array {
            $sources = [];
            $sections = [];
            foreach (glob($modelsPath . '/*.json') ?: [] as $file) {
                $schema = json_decode((string) file_get_contents($file), true);
                if (!is_array($schema) || !is_string($schema['key'] ?? null) || ModelExtension::isPatch($schema)) {
                    continue;
                }
                if ($types->classFor($schema['key']) === null) {
                    $sources[] = [$schema, 'site'];
                } else {
                    // A model class's admin file: its labels come from the class's schema (classTypes() below), its section from here.
                    $sections[] = [$schema['section'] ?? null, 'site'];
                }
            }
            foreach ($extensionModels as [, $schema, $group]) {
                $sources[] = [$schema, $group];
            }
            $keys = [];
            foreach ($sources as [$schema, $group]) {
                $keys += \Preflow\Data\ModelLabelKeys::derive($schema, $group);
                $sections[] = [$schema['section'] ?? null, $group];
            }
            // Only the class-backed types Folio lists (those with an admin file): the others never show a label.
            $keys += array_filter(
                \Preflow\Data\ModelLabelKeys::classTypes($types, 'site'),
                static fn (string $key): bool => $catalog->has(explode('.', $key)[2] ?? ''), // group.kind.type.…
                ARRAY_FILTER_USE_KEY,
            );
            $builtin = \Preflow\Folio\Http\AdminNav::sectionOrder([]);
            foreach ($sections as [$section, $group]) {
                if (is_string($section) && !in_array($section, $builtin, true)) {
                    $keys += [\Preflow\Data\ModelLabelKeys::section($group, $section) => null];
                }
            }

            return $keys;
        });
    }

    private static function translator(Container $c, Application $app): \Preflow\I18n\Translator
    {
        if ($c->bound(\Preflow\I18n\Translator::class)) {
            $translator = $c->get(\Preflow\I18n\Translator::class);
        } else {
            $locale = (string) $app->config()->get('app.locale', 'en');
            $translator = new \Preflow\I18n\Translator($app->basePath('lang'), $locale, $locale);
            $c->instance(\Preflow\I18n\Translator::class, $translator);
        }
        // Declared in composer.json (extra.preflow.translations); this covers apps whose
        // Composer metadata doesn't list Folio (test apps, a core before 0.37).
        $translator->addDefaultsIfMissing('folio', dirname(__DIR__) . '/lang', 'admin');
        $translator->addDefaultsIfMissing('members-admin', dirname(__DIR__) . '/lang', 'admin');
        $translator->addDefaultsIfMissing('rbac-admin', dirname(__DIR__) . '/lang', 'admin');
        if (class_exists(\Preflow\Validation\Message::class)) { // the messages of Folio's forms (public: site forms may use them too)
            $translator->addDefaultsIfMissing('validation', \Preflow\Validation\Message::LANG_PATH, 'public');
        }
        self::useOverrides($translator, $c);

        return $translator;
    }

    /**
     * The admins' overrides (Settings → Translations) as the translator's override layer. They
     * apply on admin requests only (AdminLocaleMiddleware turns them on): to admin groups and
     * to the site's own model labels, which are admin-only texts in the scope-less "site" group.
     * The site's editable groups (config/folio.php translations.editable) take them on every
     * request, public pages and mails included. Only when the layer is still empty
     * (NoTranslationOverrides): a site or package that set its own TranslationOverrides keeps them.
     */
    private static function useOverrides(\Preflow\I18n\Translator $translator, Container $c): void
    {
        foreach (['types', 'fields', 'options', 'sections'] as $area) {
            $translator->overridable(\Preflow\Folio\Translations\EditableTexts::SITE . '.' . $area . '.');
        }
        self::$editableApplied ??= new \WeakMap();
        if (!isset(self::$editableApplied[$translator])) { // once per translator: one log line per bad entry
            self::$editableApplied[$translator] = true;
            foreach (self::editableGroups($c->get(Application::class)) as $group) {
                if ($translator->scope($group) === 'admin') {
                    error_log('folio: translations.editable: "' . $group . '" is an admin group; its overrides stay admin-only, skipped');
                    continue;
                }
                $translator->alwaysOverride($group);
            }
        }
        if (!$translator->overrides() instanceof \Preflow\I18n\NoTranslationOverrides) {
            return;
        }
        // Built on first use (an admin request): the store needs the DataManager, which a public request never asks for here.
        $translator->setOverrides(new class ($c) implements \Preflow\I18n\TranslationOverrides {
            private ?\Preflow\Folio\Translations\StoredOverrides $store = null;

            public function __construct(private readonly Container $container) {}

            public function get(string $locale, string $key): ?string
            {
                return ($this->store ??= $this->container->get(\Preflow\Folio\Translations\StoredOverrides::class))->get($locale, $key);
            }
        });
    }

    /** @var \WeakMap<\Preflow\I18n\Translator, true>|null translators useOverrides() gave the editable groups */
    private static ?\WeakMap $editableApplied = null;

    /**
     * config/folio.php translations.editable: the site's own groups staff edit on the
     * Translations screen. An entry that isn't a group name, or is "site" (its model labels stay
     * admin-only), is skipped with a line in the error log; boot goes on.
     *
     * @return list<string>
     */
    private static function editableGroups(Application $app): array
    {
        $cfg = $app->configFile('folio') ?? [];
        $list = is_array($cfg) ? ($cfg['translations']['editable'] ?? []) : [];
        $groups = [];
        foreach (is_array($list) ? $list : [$list] as $entry) {
            $name = is_scalar($entry) ? (string) $entry : get_debug_type($entry);
            if (!is_string($entry) || preg_match('/^[a-z0-9_-]+$/i', $entry) !== 1) {
                error_log('folio: translations.editable: "' . $name . '" is not a group name, skipped');
            } elseif ($entry === \Preflow\Folio\Translations\EditableTexts::SITE) {
                error_log('folio: translations.editable: "site" holds the model labels, which stay admin-only, skipped');
            } elseif (!in_array($entry, $groups, true)) {
                $groups[] = $entry;
            }
        }

        return $groups;
    }

    /**
     * Sections that start closed (config/folio.php nav.collapsed): library and settings when not
     * set; a value that is not a list of strings is ignored (nothing starts closed).
     *
     * @param array<string, mixed> $folioCfg
     * @return list<string>
     */
    private static function navCollapsed(array $folioCfg): array
    {
        $nav = $folioCfg['nav'] ?? null;
        if (!is_array($nav) || !array_key_exists('collapsed', $nav)) {
            return ['library', 'settings'];
        }
        $collapsed = $nav['collapsed'];
        if (!is_array($collapsed) || !array_is_list($collapsed)) {
            return [];
        }
        foreach ($collapsed as $name) {
            if (!is_string($name)) {
                return [];
            }
        }

        return $collapsed;
    }

    /**
     * The admin's default interface language: config/folio.php "locale", else app.locale
     * (English when Folio has no texts in it, InterfaceLocales::default()).
     *
     * @param array<string, mixed> $folioCfg
     */
    private static function interfaceDefault(Application $app, array $folioCfg): string
    {
        $locale = $folioCfg['locale'] ?? $app->config()->get('app.locale', 'en');

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }

    /**
     * The environment the sidebar names under the site ("Folio · development"): app.env, else the
     * APP_ENV variable, else app.debug (on: development). Null in production, which the sidebar
     * leaves unsaid.
     */
    private static function environment(Application $app): ?string
    {
        $env = $app->config()->get('app.env');
        if (!is_string($env) || trim($env) === '') {
            $env = getenv('APP_ENV');
        }
        if (!is_string($env) || trim($env) === '') {
            // app.debug may be a bool, a number or a string from .env ("true", "on", "1"): (int) "true" is 0.
            $debug = $app->config()->get('app.debug', false);
            $on = filter_var($debug, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                ?? (is_numeric($debug) && (float) $debug != 0);
            $env = $on ? 'development' : 'production';
        }
        $env = strtolower(trim($env));

        return in_array($env, ['production', 'prod', 'live'], true) ? null : $env;
    }

    /**
     * admin.js's words: every folio.js.* key (as Folio's English file and the site's lang/en name
     * them), short keys ("confirm_delete"), and the redirect prompt's folio.redirects.prompt.*
     * ("redirects.prompt.leave"), in the translator's language, as JSON that cannot close or
     * break out of its script block.
     */
    private static function jsTexts(\Preflow\I18n\Translator $translator): string
    {
        $texts = [];
        foreach (array_keys($translator->entries('folio', 'en')) as $key) {
            if (str_starts_with($key, 'folio.js.')) {
                $texts[substr($key, strlen('folio.js.'))] = $translator->get($key);
            } elseif (str_starts_with($key, 'folio.redirects.prompt.')) {
                $texts[substr($key, strlen('folio.'))] = $translator->get($key);
            }
        }

        return json_encode(
            (object) $texts,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    public function boot(Container $container): void
    {
        // Folio's texts are declared in composer.json (extra.preflow.translations); this covers
        // apps whose Composer metadata doesn't list Folio (test apps). In boot: an app may
        // register Folio before it boots its translator.
        if ($container->bound(\Preflow\I18n\Translator::class)) {
            $container->get(\Preflow\I18n\Translator::class)->addDefaultsIfMissing('folio', dirname(__DIR__) . '/lang', 'admin');
            $container->get(\Preflow\I18n\Translator::class)->addDefaultsIfMissing('codes', dirname(__DIR__) . '/lang', 'admin');
            $container->get(\Preflow\I18n\Translator::class)->addDefaultsIfMissing('members-admin', dirname(__DIR__) . '/lang', 'admin');
            $container->get(\Preflow\I18n\Translator::class)->addDefaultsIfMissing('rbac-admin', dirname(__DIR__) . '/lang', 'admin');
            self::useOverrides($container->get(\Preflow\I18n\Translator::class), $container);
        }

        $app = $container->get(Application::class);
        // The admin's path prefix as the routes have it ("folio" and "/folio/" are "/folio"), "" at "/".
        $admin = $this->adminPath($app);
        // The staff login: a form left open past the session's end still signs in (CSRF).
        if ($container->bound(\Preflow\Core\Http\Csrf\CsrfSignInPaths::class)) {
            $container->get(\Preflow\Core\Http\Csrf\CsrfSignInPaths::class)->add($admin . '/login');
        }
        // The admin is not a public page: no redirects there, nothing in the not-found list.
        // Folio at "/" would cover the whole site, so nothing is registered then.
        if ($admin !== '' && $container->bound(\Preflow\Core\Http\NonPublicPaths::class)) {
            $container->get(\Preflow\Core\Http\NonPublicPaths::class)->add($admin);
        }
        self::requireRbacForMembers($container->get(ExtensionRegistry::class));
        // The codes admin's purpose select lists the purposes of config/codes.php (preflow/codes' Codes).
        if (self::codesAdmin($container->get(ExtensionRegistry::class)) && $container->bound(\Preflow\Codes\Codes::class)) {
            $container->get(FieldTypeRegistry::class)->register(new \Preflow\Folio\Codes\Fields\PurposeFieldType($container->get(\Preflow\Codes\Codes::class)->purposes()));
        }
        // The rbac admin's field types (rbac_permissions with its editor's assets, rbac_roles), wherever rbac
        // runs: the roles and staff types, the members admin and a site's model files use them. rbac's
        // services are container singletons from its register() on, whatever the provider order.
        if ($container->bound(\Preflow\Rbac\PermissionCoverage::class) && $container->bound(DataManager::class)) {
            self::registerRbacFields($container);
        }
        // ... and carries it into the published snapshots afterwards (best effort, see PublishedMemberDeletion).
        if (class_exists(\Preflow\Members\MemberEvents::class) && $container->bound(\Preflow\Members\MemberEvents::class)) {
            // Built when a member is deleted: the Publisher is not needed before.
            $container->get(\Preflow\Members\MemberEvents::class)->on(\Preflow\Members\MemberEvents::DELETED, static function (array $payload) use ($container): void {
                (new \Preflow\Folio\Members\PublishedMemberDeletion(
                    $container->get(DataManager::class),
                    $container->get(\Preflow\Publishing\Publisher::class),
                    $container->get(\Preflow\Publishing\PublishedCopies::class),
                    $container->get(\Preflow\Publishing\RecordWriter::class),
                    self::logger($container),
                ))($payload);
            });
        }
        $this->registerFieldKinds($container, $app);
        $this->declarePermissions($container, $app);
        $this->registerTextSources($container);
        // The SEO audit's findings link to the record or asset to fix. Nothing is built or read until the audit asks.
        if ($container->bound(\Preflow\View\Audit\AuditRegistry::class)) {
            $container->get(\Preflow\View\Audit\AuditRegistry::class)->setTargets(
                static fn (string $url, string $locale): ?\Preflow\View\Audit\Target => $container->get(\Preflow\Folio\Audit\AuditTargets::class)->target($url, $locale),
            );
        }

        // 1. Twig namespace: userland override dir first, package templates second.
        if ($container->has(TemplateEngineInterface::class)) {
            // Folio's engine: its Twig templates through its own Twig engine, app templates through the
            // app's; on a Twig app both are the app's engine (FolioEngine).
            $engine = $container->get(\Preflow\Folio\View\FolioEngine::class);
            $userDir = $app->basePath('resources/folio');
            if (is_dir($userDir)) {
                $engine->addNamespace('folio', $userDir);
            }
            $engine->addNamespace('folio', dirname(__DIR__) . '/templates');
            // Enabled extensions' templates/ as @{extension name}/…
            foreach ($container->get(ExtensionRegistry::class)->enabled() as $extension) {
                if (is_dir($extension->folder . '/templates')) {
                    $engine->addNamespace($extension->name, $extension->folder . '/templates');
                }
            }

            // Single asset URL seam: folio_asset('admin.css') -> versioned URL.
            // Content-hash version so immutable caches bust on edit. A future
            // asset-publishing system can swap this one resolver.
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_asset',
                callable: static fn (string $file): string => $container->get(\Preflow\Folio\Http\AdminAssetUrls::class)($file),
                isSafe: false,
            ));
            // folio_extension_asset('shop', 'shop.css') -> {prefix}/_assets/x/shop/shop.css?v=…; throws when not served.
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_extension_asset',
                callable: fn (string $extension, string $path): string => $container->get(\Preflow\Folio\Extension\ExtensionAssets::class)->url($extension, $path),
                isSafe: false,
            ));

            // The interface language of the admin request (AdminLocaleMiddleware updates it) and the
            // offered languages with their own names (the sidebar switch, the account page).
            $engine->addGlobal('interface_locale', $container->get(\Preflow\Folio\Http\InterfaceLocales::class)->default());
            // The sidebar's site switcher: the site's name and its environment (null in production).
            $engine->addGlobal('folio_site', [
                'name' => (string) ($app->config()->get('app.name') ?: 'Folio'),
                'env' => self::environment($app),
            ]);
            // The app's directory, absolute: the cron line of the no-worker hint (folio.ai_queue.no_worker, :path).
            $base = $app->basePath();
            $engine->addGlobal('folio_app_path', str_starts_with($base, '/') ? $base : (realpath($base) ?: $base));
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_interface_locales',
                callable: fn (): array => $container->get(\Preflow\Folio\Http\InterfaceLocales::class)->names(),
            ));

            // Dates and numbers in the interface language: folio_date(value, 'date'|'datetime'), folio_number(value).
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_date',
                callable: static fn (mixed $value, string $style = 'date'): string => (new \Preflow\Folio\Http\AdminFormat())->date($value, $style),
            ));
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_number',
                callable: static fn (mixed $value): string => (new \Preflow\Folio\Http\AdminFormat())->number($value),
            ));
            // A type's label inside a sentence ("No posts yet."), as the interface language writes nouns.
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_noun',
                callable: static fn (string $label): string => \Preflow\Folio\FolioText::noun($label),
            ));
            // admin.js's dictionary (_i18n.twig): the folio.js.* texts in the interface language, as JSON that is safe inside a script block.
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_js_texts',
                callable: static fn (): string => self::jsTexts(\Preflow\Folio\FolioText::translator()),
            ));

            // The sidebar: sections and items from the nav model (extensions add theirs), filtered by permission.
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_nav',
                callable: fn (): array => $container->get(\Preflow\Folio\Http\AdminNav::class)->sections(),
            ));

            // Session flashes for the admin layout (a record action's "done", a refused delete).
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_flashes',
                callable: function () use ($container): array {
                    $session = $container->has(\Preflow\Core\Http\Session\SessionInterface::class)
                        ? $container->get(\Preflow\Core\Http\Session\SessionInterface::class)
                        : null;
                    $flashes = [];
                    foreach (['success', 'warning', 'error'] as $type) {
                        $message = $session?->isStarted() ? $session->getFlash($type) : null;
                        if (is_string($message) && $message !== '') {
                            $flashes[$type] = $message;
                        }
                    }

                    return $flashes;
                },
            ));
            // The link a flash may carry ({url, label}; a page that left the site: "Send its old links to…").
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_flash_link',
                callable: function (string $type) use ($container): ?array {
                    $session = $container->has(\Preflow\Core\Http\Session\SessionInterface::class)
                        ? $container->get(\Preflow\Core\Http\Session\SessionInterface::class)
                        : null;
                    $link = $session?->isStarted() ? $session->getFlash($type . '_link') : null;

                    return is_array($link) && is_string($link['url'] ?? null) && is_string($link['label'] ?? null)
                        ? ['url' => $link['url'], 'label' => $link['label']]
                        : null;
                },
            ));

            // Singletons: folio_get('site') -> localized values (null before the first save).
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_get',
                callable: fn (string $type): ?array => $container->get(\Preflow\Folio\Content\Singletons::class)->get($type),
            ));

            // Extension settings: folio_setting('members', 'registration_open') -> the value or its default.
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_setting',
                callable: fn (string $extension, string $key): mixed => $container->get(ExtensionSettings::class)->get($extension, $key),
            ));

            // Collections: folio_list('app') -> live records in list order; folio_find('force', id).
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_list',
                callable: fn (string $type): array => $container->get(\Preflow\Folio\Content\Collections::class)->list($type),
            ));
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_find',
                callable: fn (string $type, string $id): ?array => $container->get(\Preflow\Folio\Content\Collections::class)->find($type, $id),
            ));

            // Blocks: preflow/blocks' renderer (one per app, so nested calls see the preview state).
            $blockRenderer = static fn (): \Preflow\Blocks\BlockRenderer => $container->get(\Preflow\Blocks\BlockRenderer::class);
            // Field target for click-to-edit: emitted only while a preview renders.
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_editable',
                callable: fn (string $field): string => $blockRenderer()->inPreview()
                    ? ' data-folio-edit="' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '"'
                    : '',
                isSafe: true,
            ));
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_block',
                callable: fn (array $block, bool $preview = false): string => $blockRenderer()->renderBlock(
                    \Preflow\Data\LocaleValue::resolveTree($block, $container->get(ContentLocale::class)->locales(), $container->get(ContentLocale::class)->get()),
                    $preview,
                ),
                isSafe: true,
            ));

            // Deprecated names of media_image()/media_url(): the same ImageTag, so a page using both adds its CSS and preload once.
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_image',
                callable: fn (mixed $value, ?string $preset = null, array $options = []): string => $container->get(\Preflow\Media\ImageTag::class)->render($value, $preset, $options),
                isSafe: true,
            ));
            // folio_asset_url(id): an asset's public URL ('' without one), e.g. a PDF download link.
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_asset_url',
                callable: fn (mixed $value = null): string => $container->get(\Preflow\Media\ImageTag::class)->url($value),
            ));

            // Public URLs and hreflang alternates of a (raw, stored) record.
            $resolver = static fn () => $container->get(FrontendResolver::class);
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_url',
                callable: function (string $type, string $id, ?string $locale = null) use ($resolver, $container): ?string {
                    $record = $container->get(\Preflow\Publishing\LiveContent::class)->find($type, $id);
                    return $record === null ? null : $resolver()->urlFor($record, $locale ?? $container->get(ContentLocale::class)->get());
                },
            ));
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_alternate_links',
                callable: function (array $alternates): string {
                    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (count($alternates) < 2) {
                        return '';
                    }
                    $out = '';
                    $default = $alternates[0];
                    foreach ($alternates as $alt) {
                        $out .= '<link rel="alternate" hreflang="' . $e((string) $alt['locale']) . '" href="' . $e((string) ($alt['href'] ?? $alt['url'])) . '">' . "\n";
                        $default = ($alt['default'] ?? false) ? $alt : $default;
                    }
                    return $out . '<link rel="alternate" hreflang="x-default" href="' . $e((string) ($default['href'] ?? $default['url'])) . '">' . "\n";
                },
                isSafe: true,
            ));

            // The user menu's link to the own account page: only for a record user of the staff guard.
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_account_url',
                callable: function () use ($container, $app): ?string {
                    if (!$app->hasConfigFile('auth') || self::recordProviders($container) === []) {
                        return null;
                    }

                    return $container->get(\Preflow\Auth\AuthManager::class)->user() instanceof \Preflow\Auth\Record\RecordUser
                        ? '/' . trim($this->prefix($app), '/') . '/_account'
                        : null;
                },
            ));

            if ($app->hasConfigFile('auth')) {
                $engine->addGlobal('folio_auth_enabled', true);
            } else {
                // Auth is off: AuthExtensionProvider never ran, so its Twig
                // functions are unregistered. _layout.twig guards their *calls*
                // behind folio_auth_enabled, but Twig resolves function existence
                // at compile time, so register inert stubs to keep the template
                // compilable. They are never invoked (the guard is false).
                foreach (['auth_check' => true, 'auth_user' => false, 'csrf_field' => true] as $name => $safe) {
                    $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                        name: $name,
                        callable: fn () => null,
                        isSafe: $safe,
                    ));
                }
            }
        }

        // 2. Routes: the frontend catch-all, LAST. The admin's (under the configured prefix, in front
        // of it) come with the admin setup: on the first request under the prefix, or now in the console.
        // The media core's (private files, variants) are already in: it boots before Folio.
        if ($container->has(RouterInterface::class)) {
            $router = $container->get(RouterInterface::class);
            if ($router instanceof Router) {
                // Builds the app's routes first (lazy); compiled once into the boot manifest (`preflow optimize`).
                $router->getCollection()->addMany($router->compiled('folio.frontend', static fn (): array => [FolioRoutes::frontend()]));
            }
        }

        if ($admin === '' || $app->runningInConsole()) {
            // Folio at "/" covers every request. Once every provider booted: the staff guard's
            // record provider may need a type a later provider defines (rbac's "staff").
            $app->onProvidersBooted(fn () => $this->ensureAdmin($container));
        } else {
            $app->onRequest(function (\Psr\Http\Message\ServerRequestInterface $request) use ($container, $admin): void {
                if (self::underAdmin($request->getUri()->getPath(), $admin)) {
                    $this->ensureAdmin($container);
                }
            });
        }
    }

    /** @var \WeakMap<Container, true>|null the containers whose admin is set up (ensureAdmin()) */
    private ?\WeakMap $adminReady = null;

    /**
     * Folio's admin setup, once per app (later calls do nothing): extension screens and
     * customizations validated, the sidebar built, and the admin routes (extension screens
     * first, then Folio's) registered in front of the frontend catch-all. It runs on the first
     * request under the admin prefix (Application::onRequest()), at boot in the console and
     * when Folio is at "/". A public request never needs it; services it builds are lazy
     * singletons, so code that asks for one elsewhere gets it complete.
     */
    public function ensureAdmin(Container $container): void
    {
        $this->adminReady ??= new \WeakMap();
        if (isset($this->adminReady[$container])) {
            return;
        }

        $app = $container->get(Application::class);
        // Validated on first resolution: a broken extension screen or customization fails here.
        $screens = $container->get(ScreenRegistry::class);
        $container->get(Customizations::class);
        $container->get(\Preflow\Folio\Http\AdminNav::class);

        if ($container->has(RouterInterface::class)) {
            $router = $container->get(RouterInterface::class);
            if ($router instanceof Router) {
                $authEnabled = $app->hasConfigFile('auth');
                // Screens first: their routes must win over the admin's {type} routes.
                $router->getCollection()->insertBefore(FolioRoutes::frontend(), [
                    ...$screens->routes($this->prefix($app), $authEnabled),
                    ...FolioRoutes::admin(
                        $this->prefix($app),
                        $authEnabled,
                        $authEnabled && self::recordProviders($container) !== [],
                        // The translate endpoints exist only while the machine translator is available (AiTranslator: AI is on).
                        $container->get(\Preflow\I18n\Machine\MachineTranslator::class)->available(),
                        self::aiSettingsVisible($container),
                    ),
                ]);
            }
        }
        // Done only now: a setup that threw runs again on the next admin request. The routes go in
        // last, in one insert, so a failure before leaves none to add twice.
        $this->adminReady[$container] = true;
    }

    /**
     * Whether a raw request path is under the admin prefix ($admin, no trailing slash), also
     * behind one leading segment ("/de/folio/…": the router reaches the admin behind a locale's
     * URL prefix too). A public path that only looks like it costs the admin setup, nothing else.
     */
    private static function underAdmin(string $path, string $admin): bool
    {
        if ($path === $admin || str_starts_with($path, $admin . '/')) {
            return true;
        }
        $slash = strpos($path, '/', 1);
        if ($slash === false) {
            return false;
        }
        $rest = substr($path, $slash);

        return $rest === $admin || str_starts_with($rest, $admin . '/');
    }

    /**
     * Folio's own sidebar items, added when the AdminNav is built: the type badges of the
     * customizations, the Settings screens and the extension screens.
     */
    private function adminNavItems(\Preflow\Folio\Http\AdminNav $nav, Container $container, Application $app): void
    {
        $customizations = $container->get(Customizations::class);
        $nav->setBadges(static fn (string $type): ?int => $customizations->navBadge($type));
        $base = rtrim($this->prefix($app), '/');

        // Settings → Extensions, once there is an extension (sites without any keep today's sidebar).
        if ($container->get(ExtensionRegistry::class)->all() !== []) {
            $nav->add('settings', static fn (): string => \Preflow\Folio\FolioText::get('folio.nav.extensions'), $base . '/extensions', 'ui-settings', 'folio.extensions', 900);
        }
        // Settings → Translations, on every site (the admins' overrides of interface texts).
        $nav->add('settings', static fn (): string => \Preflow\Folio\FolioText::get('folio.nav.translations'), $base . '/translations', 'ui-settings', 'translations.edit', 920);
        // Settings → Redirects, wherever Folio keeps the redirect entries (a site with a DataManager).
        if ($container->bound(\Preflow\Folio\Redirects\RedirectEntries::class)) {
            $nav->add('settings', static fn (): string => \Preflow\Folio\FolioText::get('folio.nav.redirects'), $base . '/redirects', 'ui-settings', 'redirects.manage', 930);
        }
        // Settings → Cache, on every site (Folio registers its own caches).
        $nav->add('settings', static fn (): string => \Preflow\Folio\FolioText::get('folio.nav.cache'), $base . '/cache', 'ui-settings', 'folio.cache', 950);
        // Settings → SEO audit, on every site (the last report of `preflow seo:audit`, or how to run one).
        $nav->add('settings', static fn (): string => \Preflow\Folio\FolioText::get('folio.nav.seo_audit'), $base . '/seo-audit', 'ui-settings', 'seo.audit', 940);
        // Settings → AI, while AI is switched on or a key is present (so a missing key or switch can be seen and fixed).
        if (self::aiSettingsVisible($container)) {
            $nav->add('settings', static fn (): string => \Preflow\Folio\FolioText::get('folio.nav.ai'), $base . '/ai', 'ui-settings', 'ai.settings', 960);
        }
        // Extension screens in the sidebar (filtered by their permission), except those declared nav: false.
        foreach ($container->get(ScreenRegistry::class)->all() as $screen) {
            if (!$screen['nav']) {
                continue;
            }
            $nav->add(
                $screen['section'],
                static fn (): string => \Preflow\Folio\FolioText::label($screen['label']),
                $base . '/' . $screen['path'],
                $screen['icon'],
                $screen['permission'],
                $screen['order'],
                $screen['badge'] === null ? null : static fn (): mixed => $container->get($screen['class'])->{$screen['badge']}(),
            );
        }
    }

    /** Whether the Settings → AI screens exist (AiSettingsController::visible()). */
    private static function aiSettingsVisible(Container $container): bool
    {
        return \Preflow\Folio\Http\AiSettingsController::visible($container->get(\Preflow\Ai\Ai::class));
    }

    /**
     * Folio's private-file access rules, from config/folio.php "assets" whether or not
     * config/media.php exists: private_permission (default folio.dashboard) and
     * private_login. The media settings themselves are MediaConfig's.
     *
     * @param array<string, mixed> $assets
     * @return array{private_permission: string, private_login: ?string}
     */
    private static function privateAccessConfig(array $assets): array
    {
        return [
            'private_permission' => is_string($assets['private_permission'] ?? null) ? $assets['private_permission'] : 'folio.dashboard',
            'private_login' => is_string($assets['private_login'] ?? null) && $assets['private_login'] !== '' ? $assets['private_login'] : null,
        ];
    }

    /**
     * One machine-translation text source per type of a multilingual app (types share the app's
     * locales), so the core job runner can translate Folio records. Lazy: no type definition is
     * read and no source is built until a run asks for it; a type with nothing translatable (roles,
     * staff accounts) is not offered.
     */
    private function registerTextSources(Container $container): void
    {
        if (!($this->locales($container)?->isMultilingual() ?? false)) {
            return;
        }
        $sources = $container->get(\Preflow\I18n\Machine\TextSources::class);
        foreach ($container->get(TypeCatalog::class)->all() as $listing) {
            $key = $listing->key;
            $sources->add($key, static fn (): \Preflow\Folio\Content\FolioTextSource => new \Preflow\Folio\Content\FolioTextSource(
                $key,
                $container->get(TypeCatalog::class),
                $container->get(DataManager::class),
                $container->get(\Preflow\Folio\Content\TranslationStatus::class),
                $container->get(FieldTypeRegistry::class),
                $container->get(\Preflow\Publishing\Publisher::class),
                new RecordLabeler(),
                $container->get(\Preflow\I18n\Machine\TranslationSettings::class),
                $container->get(\Preflow\Publishing\RecordWriter::class),
                $container->get(FrontendResolver::class),
            ), static fn (): bool => $container->get(\Preflow\Folio\Content\TranslationStatus::class)->translatable(
                $container->get(DataManager::class)->getTypeRegistry()?->get($key) ?? throw new \LogicException('Text sources need a TypeRegistry.'),
            ));
        }
    }

    /**
     * Folio's answers to the publishing core's questions, unless the app bound its own
     * (preflow/publishing binds the plain defaults for the rest at boot): which types publish
     * (the catalog), the rules (RecordValidator), the languages (TranslationStatus), the review
     * index, the addresses (redirects, page-cache tags), the columns Folio adds to the record
     * tables, the legacy drafts `publish` skips, and for RecordWriter and RecordDeleter the save
     * and delete hooks of the type customizations and the orderable listings. The Publisher is
     * preflow/publishing's, with its settings from config/publishing.php.
     */
    private function registerPublishing(Container $container): void
    {
        if (!$container->bound(\Preflow\Publishing\PublishingTypes::class)) {
            $container->singleton(\Preflow\Publishing\PublishingTypes::class, static fn (Container $c): \Preflow\Publishing\PublishingTypes => new \Preflow\Folio\Publishing\FolioPublishingTypes(
                $c->get(TypeCatalog::class),
            ));
        }
        if (!$container->bound(\Preflow\Publishing\RecordChecks::class)) {
            $container->singleton(\Preflow\Publishing\RecordChecks::class, static fn (Container $c): \Preflow\Publishing\RecordChecks => new \Preflow\Folio\Publishing\FolioRecordChecks(
                $c->get(\Preflow\Folio\Content\RecordValidator::class),
            ));
        }
        if (!$container->bound(\Preflow\Publishing\LanguageChecks::class)) {
            $container->singleton(\Preflow\Publishing\LanguageChecks::class, static fn (Container $c): \Preflow\Publishing\LanguageChecks => new \Preflow\Folio\Publishing\FolioLanguageChecks(
                $c->get(\Preflow\Folio\Content\TranslationStatus::class),
            ));
        }
        if (!$container->bound(\Preflow\Publishing\RecordIndex::class)) {
            $container->singleton(\Preflow\Publishing\RecordIndex::class, static fn (Container $c): \Preflow\Publishing\RecordIndex => $c->get(\Preflow\Folio\Content\ReviewIndexUpdater::class));
        }
        if (!$container->bound(\Preflow\Publishing\RecordAddresses::class)) {
            $container->singleton(\Preflow\Publishing\RecordAddresses::class, static fn (Container $c): \Preflow\Publishing\RecordAddresses => new \Preflow\Folio\Publishing\FolioRecordAddresses(
                self::addresses($c),
                // The page cache: the addresses a publish, unpublish or delete leaves and takes
                $c->has(\Preflow\Core\PageCache\PageCache::class) ? $c->get(\Preflow\Core\PageCache\PageCache::class) : null,
                $c->get(FrontendResolver::class),
            ));
        }
        if (!$container->bound(\Preflow\Publishing\RecordColumns::class)) {
            $container->singleton(\Preflow\Publishing\RecordColumns::class, static fn (): \Preflow\Publishing\RecordColumns => new \Preflow\Folio\Publishing\FolioRecordColumns());
        }
        // The save and delete hooks of the type customizations, and the orderable listings, for RecordWriter and RecordDeleter.
        if (!$container->bound(\Preflow\Publishing\SaveHooks::class)) {
            $container->singleton(\Preflow\Publishing\SaveHooks::class, static fn (Container $c): \Preflow\Publishing\SaveHooks => new \Preflow\Folio\Publishing\FolioSaveHooks(
                $c->get(Customizations::class),
            ));
        }
        if (!$container->bound(\Preflow\Publishing\RecordTypes::class)) {
            $container->singleton(\Preflow\Publishing\RecordTypes::class, static fn (Container $c): \Preflow\Publishing\RecordTypes => new \Preflow\Folio\Publishing\FolioRecordTypes(
                $c->get(TypeCatalog::class),
                static fn (): ?FrontendResolver => $c->get(FrontendResolver::class),
            ));
        }
        if (!$container->bound(\Preflow\Publishing\PublishSkips::class)) {
            $container->singleton(\Preflow\Publishing\PublishSkips::class, static fn (Container $c): \Preflow\Publishing\PublishSkips => new \Preflow\Folio\Publishing\FolioPublishSkips(
                $c->get(TypeCatalog::class),
                $c->get(DataManager::class),
            ));
        }
    }

    /**
     * The site's PSR logger as a warning sink, where one is bound; null leaves the caller to error_log().
     *
     * @return \Closure(string): void|null
     */
    private static function logger(Container $c): ?\Closure
    {
        $logger = 'Psr\Log\LoggerInterface';

        return interface_exists($logger) && $c->bound($logger)
            ? static fn (string $message) => $c->get($logger)->warning($message)
            : null;
    }

    /** The address book, when Folio's internal storage is there (see register()). */
    private static function addresses(Container $c): ?\Preflow\Folio\Redirects\RecordAddresses
    {
        return $c->bound(\Preflow\Folio\Redirects\RecordAddresses::class) ? $c->get(\Preflow\Folio\Redirects\RecordAddresses::class) : null;
    }

    /**
     * Folio's permissions: per content type "{type}.*" and its view, create,
     * edit, publish and delete; the asset library; the dashboard; each extension's
     * (declareExtensionPermissions()). The guard
     * Folio signs in with is staff, unless config/auth.php says otherwise.
     */
    private function declarePermissions(Container $container, Application $app): void
    {
        $access = $container->get(\Preflow\Auth\Access\Access::class);
        $registry = $access->registry();
        $registry->declare('folio.dashboard', 'Dashboard', 'Folio');
        $registry->declare('folio.extensions', 'Extensions', 'Folio');
        $registry->declare('folio.cache', 'Cache', 'Folio');
        $registry->declare('translations.edit', 'Translations', 'Folio');
        $registry->declare('redirects.manage', 'Redirects', 'Folio');
        $registry->declare('seo.audit', 'SEO audit', 'Folio');
        $registry->declare('seo.audit.run', 'Run the SEO audit', 'Folio');
        // Declared with AI off too, so roles may list them before AI is switched on.
        $registry->declare('ai.translate', 'Use AI translation', 'AI');
        $registry->declare('ai.settings', 'AI settings', 'AI');
        foreach ($container->get(TypeCatalog::class)->all() as $listing) {
            $registry->declarePattern($listing->key . '.*');
            foreach (['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'publish' => 'Publish', 'delete' => 'Delete'] as $action => $verb) {
                $registry->declare($listing->key . '.' . $action, $verb, $listing->label);
            }
        }
        $registry->declarePattern('assets.*');
        foreach (['view' => 'View', 'upload' => 'Upload', 'edit' => 'Edit', 'delete' => 'Delete'] as $action => $verb) {
            $registry->declare('assets.' . $action, $verb, 'Assets');
        }
        $this->declareExtensionPermissions($registry, $container->get(ExtensionRegistry::class), $container->get(TypeCatalog::class));

        if ($app->hasConfigFile('auth')) {
            // Only staff get into the admin: a guard declaring another population stops the boot here.
            $staffGuard = $container->get(\Preflow\Folio\Users\StaffGuard::class);
            $staffGuard->assertStaff((array) $app->configFile('auth'));
            $access->markStaffGuard($staffGuard->name);
        }
    }

    /** Whether the members admin is Folio's built-in one (preflow/members installed) and switched on. */
    private static function membersAdmin(ExtensionRegistry $extensions): bool
    {
        return $extensions->isEnabled('members') && $extensions->get('members')->class === \Preflow\Folio\Members\Extension::class;
    }

    /** Whether the codes admin is Folio's built-in one (preflow/codes installed) and switched on. */
    private static function codesAdmin(ExtensionRegistry $extensions): bool
    {
        return $extensions->isEnabled('codes') && $extensions->get('codes')->class === \Preflow\Folio\Codes\Extension::class;
    }

    /** The field types of the rbac admin (Rbac\Fields), and the permissions editor's assets (assets/rbac/). */
    private static function registerRbacFields(Container $container): void
    {
        $coverage = $container->get(\Preflow\Rbac\PermissionCoverage::class);
        $fieldTypes = $container->get(FieldTypeRegistry::class);
        $fieldTypes->register(new \Preflow\Folio\Rbac\Fields\PermissionsFieldType(
            $container->get(\Preflow\Auth\Access\PermissionRegistry::class),
            $container->get(TypeCatalog::class),
            $coverage,
            $container->bound(\Preflow\Folio\Content\DisplayLabels::class) ? $container->get(\Preflow\Folio\Content\DisplayLabels::class) : null,
        ));
        $assets = $container->get(\Preflow\Folio\Http\AdminAssets::class);
        $assets->add('rbac.css', dirname(__DIR__) . '/assets/rbac/rbac.css');
        $assets->add('rbac.js', dirname(__DIR__) . '/assets/rbac/rbac.js');
        $fieldTypes->register(new \Preflow\Folio\Rbac\Fields\RolesFieldType($container->get(DataManager::class), $container->get(TypeCatalog::class), $coverage, $container->get(\Preflow\Rbac\Messages::class)));
    }

    /** The members admin seeds and shows the member roles, which are rbac's. */
    private static function requireRbacForMembers(ExtensionRegistry $extensions): void
    {
        if (self::membersAdmin($extensions) && !$extensions->isEnabled('rbac')) {
            throw new \RuntimeException('The members extension needs the rbac extension for the member roles: run preflow folio:extensions:enable rbac.');
        }
    }

    /**
     * Permissions of every discovered extension, grouped under its label: its
     * #[DeclaresPermission], its screens' and record actions' permissions, and the standard
     * permissions of its model types and settings type. Those of an extension that is not
     * enabled (or of a disabled part) are declared and disabled: denied and logged once, never
     * a 500; an opt-in model the site hasn't switched on (and its type part) declares none. A name Folio declares itself (folio.*, assets.*, a non-extension type's) is an
     * ExtensionException.
     */
    private function declareExtensionPermissions(\Preflow\Auth\Access\PermissionRegistry $registry, ExtensionRegistry $extensions, TypeCatalog $catalog): void
    {
        $standard = ['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'publish' => 'Publish', 'delete' => 'Delete'];
        $declared = array_keys($registry->all()); // Folio's own plus the standard names of enabled extension types
        $modelNames = [];
        foreach ($extensions->all() as $definition) {
            foreach ($definition->parts as $part) {
                $typeKey = self::extensionTypeKey($definition->name, $part);
                if ($typeKey !== null && $extensions->partEnabled($definition->name, $part['id'])) {
                    foreach (array_keys($standard) as $action) {
                        $modelNames[$typeKey . '.' . $action] = true;
                    }
                }
            }
        }
        $builtin = array_flip(array_filter($declared, static fn (string $name): bool => !isset($modelNames[$name])));
        $active = array_flip($declared);

        /** @var list<array{name: string, label: string, group: string, reason: ?string}> $entries */
        $entries = [];
        $carried = [];
        foreach ($extensions->all() as $definition) {
            $extensionReason = $extensions->isEnabled($definition->name) ? null : "extension {$definition->name}";
            foreach ((new \ReflectionClass($definition->class))->getAttributes(\Preflow\Folio\Extension\Attributes\DeclaresPermission::class) as $attribute) {
                $meta = $attribute->newInstance();
                if (!str_starts_with($meta->name, $definition->name . '.')) {
                    throw new \Preflow\Folio\Extension\ExtensionException("{$definition->class}: permission \"{$meta->name}\" must start with \"{$definition->name}.\" (the extension's name).");
                }
                $entries[] = ['name' => $meta->name, 'label' => $meta->label, 'group' => $definition->label, 'reason' => $extensionReason];
                if (isset(ExtensionRegistry::BUILT_IN[$definition->class])) {
                    $carried[$meta->name] = true; // the package Folio carries the admin of may declare it itself (members.access)
                }
            }
            foreach ($definition->parts as $part) {
                if ($extensions->optIn($definition->name, $part['id']) && !$extensions->partEnabled($definition->name, $part['id'])) {
                    continue; // an opt-in model the site hasn't switched on (and its type part): as if not shipped
                }
                $reason = $extensionReason ?? ($extensions->partEnabled($definition->name, $part['id']) ? null : "part {$part['id']} of extension {$definition->name}");
                $typeKey = self::extensionTypeKey($definition->name, $part);
                if ($typeKey !== null && $reason !== null) {
                    if ($catalog->has($typeKey)) { // a switched-off model or settings type must not shadow the site's own type
                        throw new \Preflow\Folio\Extension\ExtensionException("Extension \"{$definition->name}\" ships " . ($part['kind'] === 'settings' ? 'settings type' : 'model') . " \"{$typeKey}\", which is already a content type of the site.");
                    }
                    foreach ($standard as $action => $verb) {
                        $entries[] = ['name' => $typeKey . '.' . $action, 'label' => $verb, 'group' => $part['kind'] === 'settings' ? $definition->label : $part['label'], 'reason' => $reason];
                    }
                } elseif ($part['kind'] === 'screen' && isset($part['class'])) {
                    $meta = (new \ReflectionClass($part['class']))->getAttributes(\Preflow\Folio\Extension\Attributes\FolioScreen::class)[0] ?? null;
                    $meta = $meta === null ? null : $this->attributeOrNull($meta);
                    if ($meta !== null && is_string($meta->permission) && $meta->permission !== '') {
                        $entries[] = ['name' => $meta->permission, 'label' => $meta->label, 'group' => $definition->label, 'reason' => $reason];
                    }
                } elseif ($part['kind'] === 'type' && isset($part['class'])) {
                    foreach ((new \ReflectionClass($part['class']))->getMethods() as $method) {
                        $meta = $method->getAttributes(\Preflow\Folio\Extension\RecordAction::class)[0] ?? null;
                        $meta = $meta === null ? null : $this->attributeOrNull($meta);
                        if ($meta !== null) {
                            $entries[] = ['name' => $meta->permission, 'label' => $meta->label, 'group' => $definition->label, 'reason' => $reason];
                        }
                    }
                }
            }
        }

        foreach ($entries as $entry) {
            if (isset($builtin[$entry['name']]) && !isset($carried[$entry['name']])) {
                throw new \Preflow\Folio\Extension\ExtensionException("Permission \"{$entry['name']}\" ({$entry['group']}) is a Folio built-in; extensions declare their own names.");
            }
            if ($entry['reason'] === null) {
                $active[$entry['name']] = true;
            }
        }
        foreach ($entries as $entry) {
            if (!$registry->isDeclared($entry['name'])) { // e.g. a record action reusing its type's own "member.edit"
                $registry->declare($entry['name'], $entry['label'], $entry['group']);
            }
            if ($entry['reason'] !== null && !isset($active[$entry['name']])) {
                $registry->disable($entry['name'], $entry['reason']);
            }
        }
    }

    /**
     * The type key an extension part defines (a model's key, the settings type), or null.
     *
     * @param array{id: string, kind: string} $part
     */
    private static function extensionTypeKey(string $extension, array $part): ?string
    {
        return match ($part['kind']) {
            'model' => substr($part['id'], 6),
            'settings' => ExtensionSettings::typeKey($extension),
            default => null,
        };
    }

    /** A part attribute's instance; one that cannot be built is the enabled-path validation's to report, not a reason to fail here. */
    private function attributeOrNull(\ReflectionAttribute $attribute): ?object
    {
        try {
            return $attribute->newInstance();
        } catch (\Error) {
            return null;
        }
    }

    /**
     * Flat URL filename => path relative to packages/folio/assets.
     *
     * @return array<string, string>
     */
    private function assetMap(): array
    {
        return [
            'admin.css' => 'admin.css',
            'admin.js' => 'admin.js',
            // Preview-only editor bridge: injected into admin preview renders, never the public site.
            'preview-editor.css' => 'preview-editor.css',
            'preview-editor.js' => 'preview-editor.js',
            'trix.css' => 'vendor/trix.css',
            'trix.js' => 'vendor/trix.umd.min.js',
            // The Edit image screen: Cropper.js (vendored) and its adapter.
            'cropper.css' => 'vendor/cropper.min.css',
            'cropper.js' => 'vendor/cropper.min.js',
            'image-editor.css' => 'image-editor.css',
            'image-editor.js' => 'image-editor.js',
            // The edit form's Translate button and review (only on its page, while AI is on).
            'ai-translate.css' => 'ai-translate.css',
            'ai-translate-core.js' => 'ai-translate-core.js',
            'ai-translate.js' => 'ai-translate.js',
            'editor-frame.js' => 'editor-frame.js',
            // The blocks editor's picker, card menus and Collapse all (with a blocks field only).
            'blocks.js' => 'blocks.js',
            'icons.svg' => 'icons.svg',
            // Self-hosted fonts (SIL OFL 1.1, licences beside them), referenced by admin.css.
            'InterVariable.woff2' => 'fonts/InterVariable.woff2',
            'JetBrainsMono-Regular.woff2' => 'fonts/JetBrainsMono-Regular.woff2',
            'JetBrainsMono-Medium.woff2' => 'fonts/JetBrainsMono-Medium.woff2',
            // The vendored htmx build from preflow/htmx (block picker fragments).
            'htmx.js' => dirname((string) (new \ReflectionClass(\Preflow\Htmx\HtmxAssets::class))->getFileName(), 2) . '/assets/htmx.min.js',
        ];
    }

    /**
     * The app's content locales. Container::has() is true for any class, and a
     * provider registered before boot sees no bound instance, so resolve
     * defensively: no Locales means single-locale content.
     */
    private function locales(Container $container): ?\Preflow\Data\Locales
    {
        try {
            return $container->get(\Preflow\Data\Locales::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Tells the data package which field types hold searchable text (the SearchableText marker,
     * extension types included) and which are relations. Only when the app has a DataManager.
     */
    private function registerFieldKinds(Container $container, Application $app): void
    {
        $dm = $this->dataManager($container, $app);
        if ($dm === null) {
            return;
        }
        $kinds = $dm->fieldKinds();
        $tell = static function (FieldType $type) use ($kinds): void {
            $key = $type->key();
            if ($type instanceof \Preflow\Folio\Field\SearchableText) {
                $kinds->text($key);
            }
            if ($type instanceof \Preflow\Folio\Field\HasColumnKind) {
                $kinds->column($key, static fn (array $config): \Preflow\Data\Schema\ColumnKind => $type->columnKind($config));
            }
        };
        $registry = $container->get(FieldTypeRegistry::class);
        foreach ($registry->all() as $type) {
            $tell($type);
        }
        // Types registered later (a package's provider booting after Folio) get the same treatment.
        $registry->whenRegistered($tell);
        $kinds->relation('relation');
    }

    /**
     * Classes installed packages list in composer.json extra.folio.field_types (read from
     * vendor/composer/installed.json through the boot manifest). Installing the package is enough.
     *
     * @param 'folio.field_types' $key the dotted path into the package's composer extra
     * @param class-string|null $mustBe the interface each class implements, or null
     * @return list<class-string>
     */
    private static function packageClasses(Application $app, string $key, ?string $mustBe = null): array
    {
        $classes = [];
        foreach ($app->installedPackages() as $package) {
            $declared = InstalledPackages::extra($package, $key);
            if ($declared === null) {
                continue;
            }
            $what = 'extra.' . $key;
            $name = (string) ($package['name'] ?? '?');
            if (!is_array($declared)) {
                throw new \RuntimeException("Package {$name}: {$what} must be a list of class names.");
            }
            foreach ($declared as $class) {
                $class = is_string($class) ? ltrim($class, '\\') : '';
                if ($class === '' || !class_exists($class)) {
                    throw new \RuntimeException("Package {$name}: {$what} lists " . ($class !== '' ? "{$class}, which is not a class" : 'a value that is not a class name') . '.');
                }
                if ($mustBe !== null && !is_subclass_of($class, $mustBe)) {
                    throw new \RuntimeException("Package {$name}: {$what} lists {$class}, which is not a {$mustBe}.");
                }
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** The app's DataManager (core builds it from config/data.php), or null without one. */
    private function dataManager(Container $container, Application $app): ?DataManager
    {
        if (!$app->hasConfigFile('data')) {
            return null;
        }
        try {
            return $container->get(DataManager::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function validatorFactory(Container $c): ?\Preflow\Validation\ValidatorFactory
    {
        try {
            return $c->get(\Preflow\Validation\ValidatorFactory::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Whether the site's URLs carry the locale (config/i18n.php url_strategy "prefix", the default). */
    private static function prefixedUrls(Application $app): bool
    {
        $i18n = (array) ($app->configFile('i18n') ?? []);

        return ($i18n['url_strategy'] ?? 'prefix') === 'prefix';
    }

    private function prefix(Application $app): string
    {
        return $this->folioConfig($app)['path'] ?? '/folio';
    }

    /**
     * The admin prefix normalised as FolioRoutes::admin() and ScreenRegistry::routes() build it:
     * one leading slash, no trailing one ("folio" → "/folio"); "" when Folio is at "/".
     */
    private function adminPath(Application $app): string
    {
        return rtrim('/' . trim($this->prefix($app), '/'), '/');
    }

    private function uploadsDir(Application $app): string
    {
        $cfg = $this->folioConfig($app);
        $path = $cfg['uploads_path'] ?? null;
        return is_string($path) ? $path : $app->basePath('storage/uploads');
    }

    private function modelsPath(Application $app): string
    {
        $data = $app->configFile('data');
        if (is_array($data) && isset($data['models_path']) && is_string($data['models_path'])) {
            return $data['models_path'];
        }
        return $app->basePath('config/models');
    }

    /**
     * Variant widths: config/folio.php assets.widths, else every width used by
     * the config/image.php presets, else a sensible ladder.
     *
     * @param array<string, mixed> $assets
     * @param array<string, mixed> $presets
     * @return list<int>
     */
    /** @return array<string, mixed> */
    private function folioConfig(Application $app): array
    {
        $path = $app->basePath('config/folio.php');
        if ($this->folioConfigCache !== null && $this->folioConfigCache[0] === $path) {
            return $this->folioConfigCache[1];
        }
        $this->folioConfigReads++;
        $cfg = $app->configFile('folio'); // from the boot manifest when there is one
        $config = is_array($cfg) ? $cfg : [];
        $this->folioConfigCache = [$path, $config];

        return $config;
    }

    /**
     * Where Folio keeps its cache files (config/folio.php 'cache_path'): an absolute path as it is, a relative
     * one from the project root; storage/cache by default. No trailing slash.
     */
    private function cacheDir(Application $app): string
    {
        $path = $this->folioConfig($app)['cache_path'] ?? null;
        if (!is_string($path) || trim($path) === '') {
            return $app->basePath('storage/cache');
        }
        $path = rtrim($path, '/\\');
        if ($path === '') {
            return $app->basePath('storage/cache');
        }
        $absolute = str_starts_with($path, '/') || preg_match('~^[A-Za-z]:~', $path) === 1;

        return $absolute ? $path : $app->basePath($path);
    }
}
