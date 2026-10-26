<?php

declare(strict_types=1);

namespace Preflow\Folio;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Routing\RouterInterface;
use Preflow\Data\DataManager;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Block\BlockRegistry;
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
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Override\ActionResolver;
use Preflow\Folio\Routing\FolioRoutes;
use Preflow\Routing\Router;
use Preflow\View\TemplateEngineInterface;

final class FolioServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        // The media core first (once, also when the app lists it): Folio adapts its services below.
        if (!$container->hasProvider(\Preflow\Media\MediaServiceProvider::class)) {
            $container->registerProvider(new \Preflow\Media\MediaServiceProvider());
        }
        $app = $container->get(Application::class);
        $modelsPath = $this->modelsPath($app);
        $prefix = $this->prefix($app);

        $catalog = new TypeCatalog($modelsPath);
        $container->instance(TypeCatalog::class, $catalog);

        // The DataManager's registry when it has one, so types defined at runtime (extension
        // models) are the ones it queries. Otherwise Folio's own, bound unconditionally:
        // Container::has() falls back to class_exists(), so a has() guard would never bind it.
        $locales = $this->locales($container);
        $dataManager = $this->dataManager($container, $app);
        $types = $dataManager?->getTypeRegistry() ?? new TypeRegistry($modelsPath, $locales);
        $container->instance(TypeRegistry::class, $types);

        // Record user providers named in config/auth.php get their user type from Folio's catalog.
        // The pre-0.38 Folio name still works, with a deprecation note.
        $recordProvider = static fn (Container $c, array $parameters = []): \Preflow\Auth\Record\RecordUserProvider => new \Preflow\Auth\Record\RecordUserProvider(
            $c->get(DataManager::class),
            (array) ($parameters['config'] ?? []),
            \Preflow\Folio\Users\RecordProviders::types($c->get(TypeCatalog::class), $c->get(TypeRegistry::class)),
        );
        $container->bind(\Preflow\Auth\Record\RecordUserProvider::class, $recordProvider);
        $container->bind('Preflow\Folio\Users\RecordUserProvider', static function (Container $c, array $parameters = []) use ($recordProvider): \Preflow\Auth\Record\RecordUserProvider {
            if ($c->bound(\Preflow\Core\Debug\DebugCollector::class)) {
                $c->get(\Preflow\Core\Debug\DebugCollector::class)->logDeprecation(
                    'config/auth.php: Preflow\Folio\Users\RecordUserProvider moved to ' . \Preflow\Auth\Record\RecordUserProvider::class . '; the old name is removed in the next release.',
                );
            }

            return $recordProvider($c, $parameters);
        });

        // Extensions (app/Folio/*, Composer extra.folio.extension), enabled by the committed
        // config/folio/extensions.json. Built before anything reads the catalog, so the models
        // of enabled extensions are types like the model files (frontend routes, permissions).
        $extensions = ExtensionRegistry::discover(
            $app->basePath(),
            debug: (bool) $app->config()->get('app.debug', false),
            cacheFile: $app->basePath('storage/cache/folio-extensions.cache'),
        );
        $container->instance(ExtensionRegistry::class, $extensions);
        // Models go under Content unless their JSON names a "section"; their settings forms
        // (Extension::settings()) are singleton types under Settings.
        // Each extension may adjust its models first (prepareModel: storage, options from config).
        // Their labels are translated in the extension's translation group (#[FolioExtension(translations: …)]).
        $extensionTypes = [];
        $labelSources = [];
        foreach ($extensions->enabled() as $definition) {
            foreach ($extensions->enabledModels($definition->name) as $schema) {
                $labelSources[] = ['extension ' . $definition->name . ' model ' . $schema['key'], $schema, $definition->labelGroup()];
                $extensionTypes[] = [$container->get($definition->class)->prepareModel($schema, $container), 'content', 'Extension model', $definition->labelGroup()];
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
        if ($extensionTypes !== [] && $dataManager !== null && $dataManager->getTypeRegistry() === null) {
            throw new \Preflow\Folio\Extension\ExtensionException("Extension models and settings need the models directory {$modelsPath} (data.models_path); create it.");
        }
        // Site model files with "extends": true add fields to these types (ModelExtension).
        $patches = ModelExtension::patches($modelsPath);
        $patchFiles = $patches === [] ? [] : ModelExtension::files($modelsPath);
        foreach ($extensionTypes as [$schema, $section, $what, $group]) {
            if (isset($patches[$schema['key']])) {
                $schema = ModelExtension::merge($schema, $patches[$schema['key']], $patchFiles[$schema['key']] ?? '');
                unset($patches[$schema['key']]);
            }
            try {
                $types->define($schema);
                $catalog->add($schema, $section, $group);
            } catch (\RuntimeException $e) {
                throw new \Preflow\Folio\Extension\ExtensionException("{$what} \"{$schema['key']}\": {$e->getMessage()}", 0, $e);
            }
        }
        if ($patches !== []) {
            throw ModelExtension::orphanError((string) array_key_first($patches), $modelsPath, $extensions);
        }
        $displayLabels = new \Preflow\Folio\Content\DisplayLabels($catalog, $types, $extensions);
        $container->instance(\Preflow\Folio\Content\DisplayLabels::class, $displayLabels);
        $this->registerLabelKeys($container, $modelsPath, $labelSources);
        // Blocks: component blocks from app/Components, data-only blocks from
        // app/Blocks, plus classes listed in config/folio.php 'blocks'. Cached
        // when debug is off (conflicting keys fail at boot either way).
        $folioCfg = $this->folioConfig($app);
        // Singletons: built once per request (discovery reflects over every component).
        $container->singleton(BlockRegistry::class, function (Container $c) use ($app, $folioCfg): BlockRegistry {
            try {
                $components = $c->get(\Preflow\Components\ComponentMap::class);
            } catch (\Throwable) {
                $components = [];
            }
            return BlockRegistry::discover(
                $components,
                $app->basePath('app/Blocks'),
                array_values((array) ($folioCfg['blocks']['classes'] ?? [])),
                $app->basePath('storage/cache/folio-blocks.cache'),
                useCache: !$app->config()->get('app.debug', false),
            );
        });

        // Both caches rebuild themselves on the next request; Settings → Cache and cache:clear list them.
        $caches = $container->get(\Preflow\Core\Cache\CacheRegistry::class);
        $caches->register(new \Preflow\Core\Cache\FileCache('folio.extensions', 'Folio extensions', $app->basePath('storage/cache/folio-extensions.cache')));
        $caches->register(new \Preflow\Core\Cache\FileCache('folio.blocks', 'Folio blocks', $app->basePath('storage/cache/folio-blocks.cache')));
        // Admin text overrides (Settings → Translations), compiled per locale.
        $overridesCache = new \Preflow\Folio\Translations\OverridesCache($app->basePath('storage/cache'));
        $container->instance(\Preflow\Folio\Translations\OverridesCache::class, $overridesCache);
        $caches->register($overridesCache);
        $container->singleton(\Preflow\Folio\Translations\StoredOverrides::class, fn (Container $c) => new \Preflow\Folio\Translations\StoredOverrides(
            $c->get(DataManager::class),
            $c->get(\Preflow\Folio\Translations\OverridesCache::class),
        ));

        // One request-scoped content locale shared by the frontend, preview and field types.
        $container->instance(ContentLocale::class, new ContentLocale($locales ?? \Preflow\Data\Locales::single('en')));

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
        foreach ([
            'Preflow\\Folio\\Assets\\AssetLibrary' => \Preflow\Media\MediaLibrary::class,
            'Preflow\\Folio\\Assets\\ImageTag' => \Preflow\Media\ImageTag::class,
            'Preflow\\Folio\\Assets\\ImageWidths' => \Preflow\Media\Image\ImageWidths::class,
        ] as $old => $new) {
            $container->bind($old, static fn (Container $c) => $c->get($new)); // deprecated names, the same services
        }
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
            [
                ...($extensions->all() !== [] ? [$c->get(\Preflow\Folio\Extension\ExtensionState::class)->definition()] : []),
                // With sign-in, the users' own settings (the interface language).
                ...(file_exists($app->basePath('config/auth.php')) ? [$c->get(\Preflow\Folio\Users\UserPreferences::class)->definition()] : []),
                // The admins' interface text overrides (Settings → Translations).
                $c->get(\Preflow\Folio\Translations\StoredOverrides::class)->definition(),
            ],
        ));
        $container->get(\Preflow\Data\Migration\MigrationRegistry::class)->afterMigrate(
            'folio.tables',
            static fn (): array => $container->get(\Preflow\Folio\Storage\TableSync::class)->sync(),
        );
        $container->singleton(\Preflow\Folio\Extension\ExtensionState::class, fn (Container $c) => new \Preflow\Folio\Extension\ExtensionState(
            $c->get(DataManager::class),
        ));
        $container->bind(\Preflow\Folio\Http\ExtensionsController::class, fn (Container $c) => new \Preflow\Folio\Http\ExtensionsController(
            $c->get(ExtensionRegistry::class),
            $c->get(\Preflow\Folio\Extension\ExtensionState::class),
            new \Preflow\Folio\Extension\ExtensionsFile($app->basePath('config/folio/extensions.json'), $modelsPath),
            $c->get(TemplateEngineInterface::class),
            $c->get(TypeCatalog::class),
            $prefix,
            // Toggling on the screen: development by default; elsewhere the committed file is the source.
            (bool) ($folioCfg['extensions']['allow_admin_changes'] ?? $app->config()->get('app.debug', false)),
        ));

        $container->bind(\Preflow\Folio\Http\TranslationsController::class, fn (Container $c) => new \Preflow\Folio\Http\TranslationsController(
            $c->get(\Preflow\Folio\Translations\StoredOverrides::class),
            new \Preflow\Folio\Translations\EditableTexts(
                self::translator($c, $app),
                $c->bound(\Preflow\I18n\DerivedKeys::class) ? $c->get(\Preflow\I18n\DerivedKeys::class) : null,
            ),
            $c->get(\Preflow\Folio\Http\InterfaceLocales::class),
            $c->get(TemplateEngineInterface::class),
            $prefix,
        ));
        $container->bind(\Preflow\Folio\Http\CacheController::class, fn (Container $c) => new \Preflow\Folio\Http\CacheController(
            $c->get(\Preflow\Core\Cache\CacheRegistry::class),
            $c->get(\Preflow\Core\Cache\OpcacheStatus::class),
            $c->get(TemplateEngineInterface::class),
            $prefix,
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
            $c->get(TemplateEngineInterface::class),
            $c->get(TypeCatalog::class),
            $locales ?? \Preflow\Data\Locales::single('en'),
            $prefix,
            $c->get(FieldTypeRegistry::class),
            $c->get(\Preflow\Media\MediaConfig::class),
            $c->get(\Preflow\Media\Image\Formats::class),
        ));

        // Request-scoped: public renders read published snapshots, admin routes switch it to working copies.
        $container->singleton(\Preflow\Folio\Content\LiveContent::class, fn (Container $c) => new \Preflow\Folio\Content\LiveContent(
            $c->get(DataManager::class),
            $c->get(TypeCatalog::class),
        ));
        $container->bind(\Preflow\Folio\Http\WorkingCopyMiddleware::class, fn (Container $c) => new \Preflow\Folio\Http\WorkingCopyMiddleware(
            $c->get(\Preflow\Folio\Content\LiveContent::class),
            $c->get(\Preflow\Folio\Http\AdminNav::class),
        ));
        $container->singleton(\Preflow\Folio\Http\AdminNav::class, function (Container $c) use ($prefix): \Preflow\Folio\Http\AdminNav {
            $access = $c->get(\Preflow\Auth\Access\Access::class);

            return new \Preflow\Folio\Http\AdminNav(
                $c->get(TypeCatalog::class),
                static fn (string $permission): bool => $access->can($permission),
                $prefix,
                $c->get(\Preflow\Folio\Content\DisplayLabels::class),
            );
        });
        $container->bind(ActionResolver::class, fn (Container $c) => new ActionResolver($c));
        // Routes are computed (and validated) now, so conflicting "frontend"
        // declarations fail at boot; the resolver itself is built lazily.
        $frontendRoutes = FrontendResolver::routesFromCatalog($container->get(TypeCatalog::class));
        $container->bind(FrontendResolver::class, fn (Container $c) => new FrontendResolver($c->get(DataManager::class), $frontendRoutes));

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
                (int) ($folioConfig['blocks']['max_depth'] ?? 4),
                self::validatorFactory($c),
                $c->get(TemplateEngineInterface::class),
                $prefix,
            ));
            $registry->register(new RichTextFieldType(new HtmlSanitizer(
                (new HtmlSanitizerConfig())
                    ->allowSafeElements()
                    ->allowRelativeLinks()
                    ->allowRelativeMedias(),
            )));
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
                $c->get(\Preflow\Folio\Content\LiveContent::class),
                $can,
                $prefix,
            ));
            $registry->register(new MatrixFieldType(
                $c->get(TypeCatalog::class),
                $c->get(TypeRegistry::class),
                $c->get(\Preflow\Data\DataManager::class),
                new RecordRenderer($registry, $c->get(TemplateEngineInterface::class), $c->get(ContentLocale::class)),
                new RecordLabeler(),
                $prefix,
                $c->get(\Preflow\Folio\Content\LiveContent::class),
                $can,
            ));
            $registry->alias('int', 'number');
            $registry->alias('integer', 'number');
            $registry->alias('float', 'number');

            // App-registered field types (config/folio.php 'field_types'); registered
            // last, so one with a built-in's key replaces it.
            foreach ((array) ($folioConfig['field_types'] ?? []) as $class) {
                $registry->register($c->get($class));
            }

            return $registry;
        });

        $container->singleton(\Preflow\Folio\Content\RecordValidator::class, fn (Container $c) => new \Preflow\Folio\Content\RecordValidator(
            $c->get(FieldTypeRegistry::class),
            $c->get(DataManager::class),
        ));
        $container->singleton(\Preflow\Folio\Publishing\Publisher::class, fn (Container $c) => new \Preflow\Folio\Publishing\Publisher(
            $c->get(DataManager::class),
            $c->get(TypeCatalog::class),
            $c->get(\Preflow\Folio\Content\RecordValidator::class),
            (int) ($folioConfig['publishing']['revisions'] ?? 20),
        ));
        $container->bind(DraftRecordFactory::class, fn (Container $c) => new DraftRecordFactory($c->get(FieldTypeRegistry::class)));
        $container->bind(AdminController::class, fn (Container $c) => new AdminController(
            $c->get(TypeCatalog::class),
            $c->get(TypeRegistry::class),
            $c->get(DataManager::class),
            $c->get(TemplateEngineInterface::class),
            $c->get(ActionResolver::class),
            $c->get(FieldTypeRegistry::class),
            $prefix,
            new RecordLabeler(),
            $c->get(FrontendResolver::class),
            $c->get(DraftRecordFactory::class),
            $c->get(\Preflow\Folio\Content\RecordValidator::class),
            $c->get(\Preflow\Folio\Publishing\Publisher::class),
            $c->get(\Preflow\Folio\Publishing\PreviewLinks::class),
            $c->get(\Preflow\Auth\Access\Access::class),
            $c->get(Customizations::class),
            new \Preflow\Folio\Users\AuthorNames($c->get(DataManager::class), static fn (): array => self::recordProviders($c)),
            pageAssets: $c->get(\Preflow\Folio\Extension\Screen\PageAssets::class),
            labels: $c->get(\Preflow\Folio\Content\DisplayLabels::class),
        ));
        $container->bind(\Preflow\Folio\Http\PublishingController::class, fn (Container $c) => new \Preflow\Folio\Http\PublishingController(
            $c->get(TypeCatalog::class),
            $c->get(\Preflow\Folio\Publishing\Publisher::class),
            $prefix,
            $c->get(DataManager::class),
            $c->get(DraftRecordFactory::class),
            $c->get(TemplateEngineInterface::class),
            $c->get(\Preflow\Folio\Publishing\PreviewLinks::class),
            $c->get(FrontendResolver::class),
            $c->get(\Preflow\Auth\Access\Access::class),
        ));
        $container->singleton(\Preflow\Folio\Publishing\PreviewLinks::class, fn () => new \Preflow\Folio\Publishing\PreviewLinks(
            (string) $app->config()->get('app.key', ''),
            (int) ($folioConfig['publishing']['preview_ttl'] ?? 604800),
        ));
        $container->bind(FrontendController::class, fn (Container $c) => new FrontendController(
            $c->get(FrontendResolver::class),
            $c->get(TemplateEngineInterface::class),
            new RecordRenderer($c->get(FieldTypeRegistry::class), $c->get(TemplateEngineInterface::class), $c->get(ContentLocale::class)),
            $c->get(ContentLocale::class),
            $c->get(DataManager::class),
            $c->get(\Preflow\Folio\Publishing\PreviewLinks::class),
            $c->get(\Preflow\Folio\Content\LiveContent::class),
            $c->get(\Preflow\View\AssetCollector::class),
            $c->get(\Preflow\Folio\Content\FrontendGate::class),
            $c->get(\Preflow\Core\Error\ErrorHandler::class),
            is_string($appUrl = $app->config()->get('app.url')) ? $appUrl : null,
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
            new RecordRenderer($c->get(FieldTypeRegistry::class), $c->get(TemplateEngineInterface::class), $c->get(ContentLocale::class)),
            $c->get(TemplateEngineInterface::class),
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
            $c->get(\Preflow\Folio\Content\LiveContent::class),
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
            $c->get(\Preflow\Folio\Content\LiveContent::class),
            $c->get(TypeCatalog::class),
            $c->get(ContentLocale::class),
            $c->get(\Preflow\Folio\Content\SafeRichText::class),
            $c->get(\Preflow\Folio\Content\FrontendGate::class),
        ));
        $container->singleton(\Preflow\Folio\Content\SafeRichText::class, fn (Container $c) => new \Preflow\Folio\Content\SafeRichText(
            $c->get(FieldTypeRegistry::class),
            $c->get(BlockRegistry::class),
        ));
        $container->bind(\Preflow\Folio\Http\BlockEditorController::class, fn (Container $c) => new \Preflow\Folio\Http\BlockEditorController(
            $c->get(FieldTypeRegistry::class),
            $locales ?? \Preflow\Data\Locales::single('en'),
            $c->get(DataManager::class),
            $c->get(TypeCatalog::class),
        ));
        $container->bind(UploadController::class, fn (Container $c) => new UploadController($uploadsDir));

        $container->bind(AdminAuthMiddleware::class, fn (Container $c) => new AdminAuthMiddleware(
            $c->get(\Preflow\Auth\AuthManager::class),
            $prefix,
            $c->get(\Preflow\Folio\Http\AdminNav::class),
        ));
        // Each user's interface language (UserPreferences), applied per admin request.
        $container->singleton(\Preflow\Folio\Users\UserPreferences::class, fn (Container $c) => new \Preflow\Folio\Users\UserPreferences(
            $c->get(DataManager::class),
        ));
        $container->singleton(\Preflow\Folio\Http\InterfaceLocales::class, fn (Container $c) => new \Preflow\Folio\Http\InterfaceLocales(
            self::translator($c, $app),
            self::interfaceDefault($app, $folioCfg),
        ));
        $container->bind(\Preflow\Folio\Http\AdminLocaleMiddleware::class, fn (Container $c) => new \Preflow\Folio\Http\AdminLocaleMiddleware(
            self::translator($c, $app),
            $c->get(\Preflow\Folio\Http\InterfaceLocales::class),
            $c->get(\Preflow\Folio\Users\UserPreferences::class),
            $c->has(TemplateEngineInterface::class) ? $c->get(TemplateEngineInterface::class) : null,
            static fn () => $c->get(\Preflow\Folio\Translations\OverridesCache::class)->startRequest(),
            self::validatorFactory($c),
        ));
        $container->bind(\Preflow\Folio\Http\LocaleController::class, fn (Container $c) => new \Preflow\Folio\Http\LocaleController(
            $c->get(\Preflow\Folio\Users\UserPreferences::class),
            $c->get(\Preflow\Folio\Http\InterfaceLocales::class),
            self::translator($c, $app),
            $prefix,
        ));
        $container->bind(\Preflow\Folio\Http\FolioPermissionMiddleware::class, fn (Container $c) => new \Preflow\Folio\Http\FolioPermissionMiddleware(
            $c->get(\Preflow\Auth\Access\Access::class),
            $c->get(TypeCatalog::class),
            $c->get(TemplateEngineInterface::class),
            $prefix,
            $c->get(ScreenRegistry::class),
        ));
        // Extension screens: validated when first resolved (in boot()), so collisions fail at boot.
        $container->singleton(ScreenRegistry::class, fn (Container $c) => ScreenRegistry::fromExtensions(
            $c->get(ExtensionRegistry::class),
            $c->get(TypeCatalog::class),
        ));
        // Type customizations: validated when first resolved (in boot()); their classes come from the container.
        $container->singleton(Customizations::class, fn (Container $c) => Customizations::fromExtensions(
            $c->get(ExtensionRegistry::class),
            $c->get(TypeCatalog::class),
            static fn (string $class): object => $c->get($class),
            $c->get(TypeRegistry::class),
        ));
        $container->bind(\Preflow\Folio\Extension\Screen\ScreenDispatcher::class, fn (Container $c) => new \Preflow\Folio\Extension\Screen\ScreenDispatcher(
            $c,
            $c->get(ScreenRegistry::class),
            $c->get(TemplateEngineInterface::class),
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
                $c->get(\Preflow\Auth\UserProviderInterface::class),
                $c->get(TemplateEngineInterface::class),
                $prefix,
                $passwords,
                $passwords === null ? null : $c->get(\Preflow\Core\Throttle\Throttle::class),
                $passwords === null ? null : $c->get(\Preflow\Auth\PasswordHasherInterface::class),
            );
        });
        $container->singleton(\Preflow\Folio\Users\StaffPasswords::class, fn (Container $c) => self::staffPasswords($c, $prefix));
        $container->bind(\Preflow\Folio\Http\AccountController::class, fn (Container $c) => new \Preflow\Folio\Http\AccountController(
            $c->get(\Preflow\Folio\Users\StaffPasswords::class),
            $c->get(\Preflow\Core\Throttle\Throttle::class),
            $c->get(\Preflow\Auth\AuthManager::class),
            $c->get(TemplateEngineInterface::class),
            $c->get(TypeCatalog::class),
            $prefix,
            max(1, (int) ($this->folioConfig($app)['password_min'] ?? 10)),
        ));
        $container->bind(\Preflow\Folio\Http\PasswordController::class, fn (Container $c) => new \Preflow\Folio\Http\PasswordController(
            $c->get(\Preflow\Folio\Users\StaffPasswords::class),
            $c->get(\Preflow\Core\Throttle\Throttle::class),
            $c->get(\Preflow\Auth\AuthManager::class),
            $c->get(TemplateEngineInterface::class),
            $prefix,
            max(1, (int) ($this->folioConfig($app)['password_min'] ?? 10)),
        ));
    }

    /**
     * The record providers (Folio user types) behind the default guard; none without auth.
     *
     * @return list<\Preflow\Auth\Record\RecordUserProvider>
     */
    private static function recordProviders(Container $c): array
    {
        try {
            $users = $c->get(\Preflow\Auth\UserProviderInterface::class);
        } catch (\Throwable) { // no config/auth.php: nothing bound
            return [];
        }

        return \Preflow\Folio\Users\RecordProviders::of($users);
    }

    private static function staffPasswords(Container $c, string $prefix): \Preflow\Folio\Users\StaffPasswords
    {
        $app = $c->get(Application::class);
        $url = $app->config()->get('app.url');
        $base = is_string($url) ? rtrim(trim($url), '/') : ''; // unset: no links are mailed (StaffPasswords::canMail)
        $auth = $c->get(\Preflow\Auth\AuthManager::class);

        return new \Preflow\Folio\Users\StaffPasswords(
            $c->get(\Preflow\Auth\Tokens\Tokens::class),
            $c->has(\Preflow\Auth\Remember\RememberMe::class) ? $c->get(\Preflow\Auth\Remember\RememberMe::class) : null,
            $c->get(\Preflow\Auth\PasswordHasherInterface::class),
            $c->get(DataManager::class),
            $c->get(\Preflow\Auth\UserProviderInterface::class),
            self::recordProviders($c),
            static function () use ($c, $app): \Preflow\Folio\Users\StaffMailer {
                return new \Preflow\Folio\Users\StaffMailer(
                    $c->get(\Preflow\Notify\Notifier::class),
                    self::translator($c, $app),
                    $c->get(\Preflow\Data\Locales::class)->default, // for recipients without an interface language
                    (string) $app->config()->get('app.name', 'Folio'),
                );
            },
            $auth->defaultGuardName(),
            $base === '' ? '' : $base . $prefix . '/password',
            $c->get(\Preflow\Folio\Users\UserPreferences::class),
        );
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
    private function registerLabelKeys(Container $container, string $modelsPath, array $extensionModels): void
    {
        if (!class_exists(\Preflow\I18n\DerivedKeys::class)) {
            return;
        }
        if (!$container->bound(\Preflow\I18n\DerivedKeys::class)) {
            $container->instance(\Preflow\I18n\DerivedKeys::class, new \Preflow\I18n\DerivedKeys());
        }
        $container->get(\Preflow\I18n\DerivedKeys::class)->add('Folio model labels', static function () use ($modelsPath, $extensionModels): array {
            $sources = [];
            foreach (glob($modelsPath . '/*.json') ?: [] as $file) {
                $schema = json_decode((string) file_get_contents($file), true);
                if (is_array($schema) && is_string($schema['key'] ?? null) && !ModelExtension::isPatch($schema)) {
                    $sources[] = [$schema, 'site'];
                }
            }
            foreach ($extensionModels as [, $schema, $group]) {
                $sources[] = [$schema, $group];
            }
            $builtin = \Preflow\Folio\Http\AdminNav::sectionOrder([]);
            $keys = [];
            foreach ($sources as [$schema, $group]) {
                $keys += \Preflow\Data\ModelLabelKeys::derive($schema, $group);
                $section = $schema['section'] ?? null;
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
     * Only when the layer is still empty (NoTranslationOverrides): a site or package that set
     * its own TranslationOverrides keeps them.
     */
    private static function useOverrides(\Preflow\I18n\Translator $translator, Container $c): void
    {
        foreach (['types', 'fields', 'options', 'sections'] as $area) {
            $translator->overridable(\Preflow\Folio\Translations\EditableTexts::SITE . '.' . $area . '.');
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
     * admin.js's words: every folio.js.* key (as Folio's English file and the site's lang/en name
     * them), short keys ("confirm_delete"), in the translator's language, as JSON that cannot
     * close or break out of its script block.
     */
    private static function jsTexts(\Preflow\I18n\Translator $translator): string
    {
        $texts = [];
        foreach (array_keys($translator->entries('folio', 'en')) as $key) {
            if (str_starts_with($key, 'folio.js.')) {
                $texts[substr($key, strlen('folio.js.'))] = $translator->get($key);
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
            self::useOverrides($container->get(\Preflow\I18n\Translator::class), $container);
        }

        $app = $container->get(Application::class);
        $screens = $container->get(ScreenRegistry::class);
        $this->registerFieldKinds($container, $app);
        $customizations = $container->get(Customizations::class);
        // Set here, not in the AdminNav factory: Customizations validate extensions and resolve only now.
        $container->get(\Preflow\Folio\Http\AdminNav::class)->setBadges(static fn (string $type): ?int => $customizations->navBadge($type));
        $this->declarePermissions($container, $app);

        // Settings → Extensions, once there is an extension (sites without any keep today's sidebar).
        if ($container->get(ExtensionRegistry::class)->all() !== []) {
            $container->get(\Preflow\Folio\Http\AdminNav::class)->add('settings', static fn (): string => \Preflow\Folio\FolioText::get('folio.nav.extensions'), rtrim($this->prefix($app), '/') . '/extensions', 'ui-settings', 'folio.extensions', 900);
        }
        // Settings → Translations, on every site (the admins' overrides of interface texts).
        $container->get(\Preflow\Folio\Http\AdminNav::class)->add('settings', static fn (): string => \Preflow\Folio\FolioText::get('folio.nav.translations'), rtrim($this->prefix($app), '/') . '/translations', 'ui-settings', 'translations.edit', 920);
        // Settings → Cache, on every site (Folio registers its own caches).
        $container->get(\Preflow\Folio\Http\AdminNav::class)->add('settings', static fn (): string => \Preflow\Folio\FolioText::get('folio.nav.cache'), rtrim($this->prefix($app), '/') . '/cache', 'ui-settings', 'folio.cache', 950);
        // Extension screens in the sidebar (filtered by their permission).
        foreach ($screens->all() as $screen) {
            $container->get(\Preflow\Folio\Http\AdminNav::class)->add(
                $screen['section'],
                $screen['label'],
                rtrim($this->prefix($app), '/') . '/' . $screen['path'],
                $screen['icon'],
                $screen['permission'],
                $screen['order'],
            );
        }

        // 1. Twig namespace: userland override dir first, package templates second.
        if ($container->has(TemplateEngineInterface::class)) {
            $engine = $container->get(TemplateEngineInterface::class);
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
            $assetUrls = $container->get(\Preflow\Folio\Http\AdminAssetUrls::class);
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_asset',
                callable: fn (string $file): string => $assetUrls($file),
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
                    foreach (['success', 'error'] as $type) {
                        $message = $session?->isStarted() ? $session->getFlash($type) : null;
                        if (is_string($message) && $message !== '') {
                            $flashes[$type] = $message;
                        }
                    }

                    return $flashes;
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

            // Blocks: rendered through their components in the content locale.
            // One renderer per app, so nested folio_blocks() calls see the preview state.
            $renderer = null;
            $blockRenderer = static function () use (&$renderer, $container): \Preflow\Folio\Block\BlockRenderer {
                return $renderer ??= new \Preflow\Folio\Block\BlockRenderer(
                    $container->get(BlockRegistry::class),
                    $container->get(\Preflow\Components\ComponentRenderer::class),
                    $container,
                    $container->get(ContentLocale::class),
                    $container->get(\Preflow\Folio\Content\SafeRichText::class),
                );
            };
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_blocks',
                callable: fn (mixed $blocks, bool $preview = false): string => $blockRenderer()->render($blocks, $preview),
                isSafe: true,
            ));
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
                    $record = $container->get(\Preflow\Folio\Content\LiveContent::class)->find($type, $id);
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

            // The user menu's link to the own account page: only for a record user of the default guard.
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_account_url',
                callable: function () use ($container, $app): ?string {
                    if (!file_exists($app->basePath('config/auth.php')) || self::recordProviders($container) === []) {
                        return null;
                    }

                    return $container->get(\Preflow\Auth\AuthManager::class)->user() instanceof \Preflow\Auth\Record\RecordUser
                        ? '/' . trim($this->prefix($app), '/') . '/_account'
                        : null;
                },
            ));

            if (file_exists($app->basePath('config/auth.php'))) {
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

        // 2. Routes: admin under the configured prefix, then the frontend catch-all LAST.
        // The media core's (private files, variants) are already in: it boots before Folio.
        if ($container->has(RouterInterface::class)) {
            $router = $container->get(RouterInterface::class);
            if ($router instanceof Router) {
                $collection = $router->getCollection(); // builds app routes first (lazy)
                $authEnabled = file_exists($app->basePath('config/auth.php'));
                // Screens first: their routes must win over the admin's {type} routes.
                $collection->addMany($screens->routes($this->prefix($app), $authEnabled));
                $collection->addMany(FolioRoutes::admin($this->prefix($app), $authEnabled, $authEnabled && self::recordProviders($container) !== []));
                $collection->add(FolioRoutes::frontend());
            }
        }
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

        if (file_exists($app->basePath('config/auth.php'))) {
            $access->markStaffGuard($container->get(\Preflow\Auth\AuthManager::class)->defaultGuardName());
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
        foreach ($extensions->all() as $definition) {
            $extensionReason = $extensions->isEnabled($definition->name) ? null : "extension {$definition->name}";
            foreach ((new \ReflectionClass($definition->class))->getAttributes(\Preflow\Folio\Extension\Attributes\DeclaresPermission::class) as $attribute) {
                $meta = $attribute->newInstance();
                if (!str_starts_with($meta->name, $definition->name . '.')) {
                    throw new \Preflow\Folio\Extension\ExtensionException("{$definition->class}: permission \"{$meta->name}\" must start with \"{$definition->name}.\" (the extension's name).");
                }
                $entries[] = ['name' => $meta->name, 'label' => $meta->label, 'group' => $definition->label, 'reason' => $extensionReason];
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
            if (isset($builtin[$entry['name']])) {
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
            'icons.svg' => 'icons.svg',
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
        foreach ($container->get(FieldTypeRegistry::class)->all() as $key => $type) {
            if ($type instanceof \Preflow\Folio\Field\SearchableText) {
                $kinds->text($key);
            }
        }
        $kinds->relation('relation');
    }

    /** The app's DataManager (core builds it from config/data.php), or null without one. */
    private function dataManager(Container $container, Application $app): ?DataManager
    {
        if (!is_file($app->basePath('config/data.php'))) {
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

    private function prefix(Application $app): string
    {
        return $this->folioConfig($app)['path'] ?? '/folio';
    }

    private function uploadsDir(Application $app): string
    {
        $cfg = $this->folioConfig($app);
        $path = $cfg['uploads_path'] ?? null;
        return is_string($path) ? $path : $app->basePath('storage/uploads');
    }

    private function modelsPath(Application $app): string
    {
        $dataConfigPath = $app->basePath('config/data.php');
        if (is_file($dataConfigPath)) {
            $data = require $dataConfigPath;
            if (is_array($data) && isset($data['models_path']) && is_string($data['models_path'])) {
                return $data['models_path'];
            }
        }
        return $app->basePath('config/models');
    }

    /** @return array<string, mixed> */
    /**
     * Variant widths: config/folio.php assets.widths, else every width used by
     * the config/image.php presets, else a sensible ladder.
     *
     * @param array<string, mixed> $assets
     * @param array<string, mixed> $presets
     * @return list<int>
     */
    private function folioConfig(Application $app): array
    {
        $path = $app->basePath('config/folio.php');
        if (is_file($path)) {
            $cfg = require $path;
            if (is_array($cfg)) {
                return $cfg;
            }
        }
        return [];
    }
}
