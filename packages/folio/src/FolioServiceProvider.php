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
        $app = $container->get(Application::class);
        $modelsPath = $this->modelsPath($app);
        $prefix = $this->prefix($app);

        $container->instance(TypeCatalog::class, new TypeCatalog($modelsPath));

        // Bind unconditionally: Container::has() falls back to class_exists(),
        // so the guard would always be false and the instance would never be bound.
        $locales = $this->locales($container);
        $container->instance(TypeRegistry::class, new TypeRegistry($modelsPath, $locales));
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

        // One request-scoped content locale shared by the frontend, preview and field types.
        $container->instance(ContentLocale::class, new ContentLocale($locales ?? \Preflow\Data\Locales::single('en')));

        // Media library: files in a public, web-server-served directory (no PHP on delivery).
        $assetsConfig = (array) ($folioCfg['assets'] ?? []);
        // Eager widths (made at upload) and the on-demand allow-list, from config/image.php presets.
        $container->singleton(\Preflow\Folio\Assets\ImageWidths::class, fn () => \Preflow\Folio\Assets\ImageWidths::fromConfig(
            (array) $app->config()->get('image.presets', []),
            is_array($assetsConfig['widths'] ?? null) ? $assetsConfig['widths'] : null,
        ));
        $container->singleton(\Preflow\Folio\Assets\AssetLibrary::class, fn (Container $c) => new \Preflow\Folio\Assets\AssetLibrary(
            $c->get(DataManager::class),
            $locales ?? \Preflow\Data\Locales::single('en'),
            (string) ($assetsConfig['dir'] ?? $app->basePath('public/media')),
            (string) ($assetsConfig['url'] ?? '/media'),
            $assetsConfig + ['warm_widths' => $c->get(\Preflow\Folio\Assets\ImageWidths::class)->allowed()],
            new \Preflow\Folio\Assets\VariantGenerator(
                \Preflow\Folio\Assets\Image\ImageBackends::detect(),
                $c->get(\Preflow\Folio\Assets\ImageWidths::class)->eager(),
                (int) ($assetsConfig['quality'] ?? 75),
            ),
        ));

        // `preflow migrate` creates the tables of SQL-stored types from their model JSON
        // (the asset table too when config/folio.php names its storage).
        $container->get(\Preflow\Data\Migration\MigrationRegistry::class)->afterMigrate(
            'folio.tables',
            static fn (): array => (new \Preflow\Folio\Storage\TableSync(
                $container->get(TypeCatalog::class),
                $container->get(TypeRegistry::class),
                $container->get(DataManager::class),
                isset($assetsConfig['storage']) ? $container->get(\Preflow\Folio\Assets\AssetLibrary::class)->definition() : null,
            ))->sync(),
        );

        $container->bind(\Preflow\Folio\Http\MediaVariantController::class, fn (Container $c) => new \Preflow\Folio\Http\MediaVariantController(
            $c->get(\Preflow\Folio\Assets\AssetLibrary::class),
            $c->get(\Preflow\Folio\Assets\ImageWidths::class),
        ));
        $container->singleton(\Preflow\Folio\Assets\AssetReferences::class, fn (Container $c) => new \Preflow\Folio\Assets\AssetReferences(
            $c->get(BlockRegistry::class),
        ));
        $container->bind(\Preflow\Folio\Assets\AssetUsage::class, fn (Container $c) => new \Preflow\Folio\Assets\AssetUsage(
            $c->get(\Preflow\Folio\Assets\AssetReferences::class),
            $c->get(DataManager::class),
            $c->get(TypeCatalog::class),
            $c->get(TypeRegistry::class),
            $c->get(\Preflow\Folio\Assets\AssetLibrary::class),
            $prefix,
        ));
        $container->bind(\Preflow\Folio\Http\AssetLibraryController::class, fn (Container $c) => new \Preflow\Folio\Http\AssetLibraryController(
            $c->get(\Preflow\Folio\Assets\AssetLibrary::class),
            $c->get(\Preflow\Folio\Assets\AssetUsage::class),
            $c->get(TemplateEngineInterface::class),
            $c->get(TypeCatalog::class),
            $locales ?? \Preflow\Data\Locales::single('en'),
            $prefix,
        ));
        $container->singleton(\Preflow\Folio\Assets\ImageTag::class, fn (Container $c) => new \Preflow\Folio\Assets\ImageTag(
            $c->get(\Preflow\Folio\Assets\AssetLibrary::class),
            $c->get(ContentLocale::class),
            (array) $app->config()->get('image.presets', []),
            $c->get(\Preflow\View\AssetCollector::class),
            rtrim($prefix, '/') . '/_uploads',
            $c->get(\Preflow\Folio\Assets\ImageWidths::class),
        ));

        // Request-scoped: public renders read published snapshots, admin routes switch it to working copies.
        $container->singleton(\Preflow\Folio\Content\LiveContent::class, fn (Container $c) => new \Preflow\Folio\Content\LiveContent(
            $c->get(DataManager::class),
            $c->get(TypeCatalog::class),
        ));
        $container->bind(\Preflow\Folio\Http\WorkingCopyMiddleware::class, fn (Container $c) => new \Preflow\Folio\Http\WorkingCopyMiddleware(
            $c->get(\Preflow\Folio\Content\LiveContent::class),
        ));
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
                $c->get(\Preflow\Folio\Assets\ImageTag::class),
                $c->get(\Preflow\Folio\Assets\AssetLibrary::class),
                $prefix,
                $can,
            ));
            $registry->register(new RelationFieldType(
                $c->get(\Preflow\Data\DataManager::class),
                $c->get(TypeRegistry::class),
                $c->get(ContentLocale::class),
                $c->get(\Preflow\Folio\Content\LiveContent::class),
                $can,
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
        ));
        $container->singleton(\Preflow\Folio\Http\AdminAssetUrls::class, fn () => new \Preflow\Folio\Http\AdminAssetUrls(
            rtrim($this->prefix($app), '/'),
            dirname(__DIR__) . '/assets',
            $this->assetMap(),
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
        ));
        $container->bind(AssetController::class, fn (Container $c) => new AssetController(
            dirname(__DIR__) . '/assets',
            $this->assetMap(),
        ));
        $container->singleton(\Preflow\Folio\Content\Singletons::class, fn (Container $c) => new \Preflow\Folio\Content\Singletons(
            $c->get(DataManager::class),
            $c->get(TypeCatalog::class),
            $c->get(ContentLocale::class),
            $c->get(\Preflow\Folio\Content\LiveContent::class),
            $c->get(\Preflow\Folio\Content\SafeRichText::class),
        ));
        $container->singleton(\Preflow\Folio\Content\Collections::class, fn (Container $c) => new \Preflow\Folio\Content\Collections(
            $c->get(\Preflow\Folio\Content\LiveContent::class),
            $c->get(TypeCatalog::class),
            $c->get(ContentLocale::class),
            $c->get(\Preflow\Folio\Content\SafeRichText::class),
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
        ));
        $container->bind(\Preflow\Folio\Http\FolioPermissionMiddleware::class, fn (Container $c) => new \Preflow\Folio\Http\FolioPermissionMiddleware(
            $c->get(\Preflow\Auth\Access\Access::class),
            $c->get(TypeCatalog::class),
            $c->get(TemplateEngineInterface::class),
            $prefix,
        ));
        $container->bind(AuthController::class, fn (Container $c) => new AuthController(
            $c->get(\Preflow\Auth\AuthManager::class),
            $c->get(\Preflow\Auth\UserProviderInterface::class),
            $c->get(TemplateEngineInterface::class),
            $prefix,
        ));
    }

    public function boot(Container $container): void
    {
        $app = $container->get(Application::class);
        $this->declarePermissions($container, $app);

        // 1. Twig namespace: userland override dir first, package templates second.
        if ($container->has(TemplateEngineInterface::class)) {
            $engine = $container->get(TemplateEngineInterface::class);
            $userDir = $app->basePath('resources/folio');
            if (is_dir($userDir)) {
                $engine->addNamespace('folio', $userDir);
            }
            $engine->addNamespace('folio', dirname(__DIR__) . '/templates');

            // Single asset URL seam: folio_asset('admin.css') -> versioned URL.
            // Content-hash version so immutable caches bust on edit. A future
            // asset-publishing system can swap this one resolver.
            $assetUrls = $container->get(\Preflow\Folio\Http\AdminAssetUrls::class);
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_asset',
                callable: fn (string $file): string => $assetUrls($file),
                isSafe: false,
            ));

            // Singletons: folio_get('site') -> localized values (null before the first save).
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_get',
                callable: fn (string $type): ?array => $container->get(\Preflow\Folio\Content\Singletons::class)->get($type),
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

            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_image',
                callable: fn (mixed $value, ?string $preset = null, array $options = []): string => $container->get(\Preflow\Folio\Assets\ImageTag::class)->render($value, $preset, $options),
                isSafe: true,
            ));
            // folio_asset_url(id): an asset's public URL ('' without one), e.g. a PDF download link.
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_asset_url',
                callable: fn (mixed $value = null): string => $container->get(\Preflow\Folio\Assets\ImageTag::class)->url($value),
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
        if ($container->has(RouterInterface::class)) {
            $router = $container->get(RouterInterface::class);
            if ($router instanceof Router) {
                $collection = $router->getCollection(); // builds app routes first (lazy)
                $authEnabled = file_exists($app->basePath('config/auth.php'));
                $collection->addMany(FolioRoutes::admin($this->prefix($app), $authEnabled));
                $assets = (array) ($this->folioConfig($app)['assets'] ?? []);
                $collection->add(FolioRoutes::media(is_string($assets['url'] ?? null) ? $assets['url'] : '/media'));
                $collection->add(FolioRoutes::frontend());
            }
        }
    }

    /**
     * Folio's permissions: per content type "{type}.*" and its view, create,
     * edit, publish and delete; the asset library; the dashboard. The guard
     * Folio signs in with is staff, unless config/auth.php says otherwise.
     */
    private function declarePermissions(Container $container, Application $app): void
    {
        $access = $container->get(\Preflow\Auth\Access\Access::class);
        $registry = $access->registry();
        $registry->declare('folio.dashboard', 'Dashboard', 'Folio');
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

        if (file_exists($app->basePath('config/auth.php'))) {
            $access->markStaffGuard($container->get(\Preflow\Auth\AuthManager::class)->defaultGuardName());
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
