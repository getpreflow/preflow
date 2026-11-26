<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Components\ComponentMap;
use Preflow\Components\ComponentRenderer;
use Preflow\Core\Application;
use Preflow\Core\Cache\CacheRegistry;
use Preflow\Core\Cache\FileCache;
use Preflow\Core\Composer\InstalledPackages;
use Preflow\Core\Container\Container;
use Preflow\Blocks\Describe\ContentDescription;
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Blocks\Layouts\Layout;
use Preflow\Blocks\Layouts\LayoutConfigException;
use Preflow\Blocks\Layouts\LayoutInput;
use Preflow\Blocks\Layouts\LayoutRegistry;
use Preflow\Blocks\Layouts\LayoutResource;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Layouts\Rules\AreaContents;
use Preflow\Blocks\Layouts\Rules\LayoutChoice;
use Preflow\Blocks\Layouts\Rules\PinnedSlug;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldKinds;
use Preflow\Data\Locales;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * Blocks: the registry of every class carrying #[Block] — component blocks found under
 * app/Components, data-only blocks under app/Blocks (App\Blocks\…), the classes installed
 * packages list in composer.json extra.preflow.blocks and config/blocks.php 'classes' —
 * and the renderer behind the template function blocks(items, preview = false).
 *
 * The registry is a boot-manifest catalog ("blocks"); without a manifest it is cached in
 * blocks.cache when debug is off (Settings → Cache and cache:clear list it). The renderer
 * asks BlockLocale, BlockTextCleaner and BlockPreviewMarks: a provider (the app's, Folio)
 * binds its own in register(), and boot() binds the plain defaults for the rest.
 *
 * Page layouts (since 0.92): the layout classes (LayoutRegistry, found and cached like blocks)
 * and, with a type registry, PageLayouts for the types with "layouts" — their option checked at
 * boot (kept in the boot manifest), and with a DataManager the layout rules on every validated
 * save of their records (RecordRules): the layout choice, a pinned page's slug (drafts too) and
 * what the areas hold.
 *
 * For APIs (since 0.92): ContentDescription (blocks, layouts, area layout sets and type layouts as
 * data for a schema builder) and, with page layouts, LayoutResource and LayoutInput — a page's
 * layout and areas read and written as JSON under the same core rules as Folio's form.
 *
 * Block values (since 0.92): with a DataManager, BlockValueChecks runs on every validated write of
 * a typed record (a RecordRules check), so a seed, an import or a REST save is refused for a block
 * value Folio's form refuses, with the same keys and messages.
 *
 * Listed in config/providers.php by a site without Folio; Folio registers it itself.
 */
final class BlocksServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $app = $container->get(Application::class);

        $container->singleton(BlocksConfig::class, static fn (): BlocksConfig => BlocksConfig::fromApp($app));
        // Packages add their checks of block fields in register() (preflow/fields does); one instance, whoever registers first.
        if (!$container->bound(BlockFieldChecks::class)) {
            $container->instance(BlockFieldChecks::class, new BlockFieldChecks());
        }

        // Built once per request (discovery reflects over every component); conflicting keys fail when it is built.
        $container->singleton(BlockRegistry::class, static function (Container $c) use ($app): BlockRegistry {
            $config = $c->get(BlocksConfig::class);
            try {
                $components = $c->get(ComponentMap::class);
            } catch (\Throwable) {
                $components = [];
            }

            return BlockRegistry::fromArray($app->bootManifest()->remember('blocks', static fn (): array => BlockRegistry::discover(
                $components,
                $app->basePath('app/Blocks'),
                // Installed packages' blocks (composer.json extra.preflow.blocks), then config/blocks.php's.
                [...self::packageClasses($app), ...$config->classes],
                $config->cacheFile,
                useCache: !$app->config()->get('app.debug', false),
                checks: $c->get(BlockFieldChecks::class)->all(),
            )->toArray(), [$app->basePath('app/Blocks'), $app->basePath('app/Components')]));
        });
        $app->bootManifest()->whenBuilding(static function (Application $app): void {
            $app->container()->get(BlockRegistry::class);
        });

        // Page layouts (since 0.92): found and cached like blocks.
        $container->singleton(LayoutRegistry::class, static function (Container $c) use ($app): LayoutRegistry {
            $config = $c->get(BlocksConfig::class);

            return LayoutRegistry::fromArray($app->bootManifest()->remember('blocks.layouts', static fn (): array => LayoutRegistry::discover(
                $app->basePath('app/Layouts'),
                [...self::packageClasses($app, 'layouts'), ...$config->layouts],
                $config->layoutsCacheFile,
                useCache: !$app->config()->get('app.debug', false),
            )->toArray(), [$app->basePath('app/Layouts')]));
        });
        $app->bootManifest()->whenBuilding(static function (Application $app): void {
            $app->container()->get(LayoutRegistry::class);
        });
        // The area layout sets packages register (the columns block's column layouts), by name.
        $container->singleton(AreaLayoutSets::class, static fn (): AreaLayoutSets => new AreaLayoutSets());

        // One renderer per app, so nested blocks() calls see the preview state and the depth.
        $container->singleton(BlockRenderer::class, static fn (Container $c): BlockRenderer => new BlockRenderer(
            $c->get(BlockRegistry::class),
            $c->get(ComponentRenderer::class),
            $c,
            $c->get(BlockLocale::class),
            $c->get(BlockTextCleaner::class),
            $c->get(BlockPreviewMarks::class),
            $c->get(BlocksConfig::class)->maxDepth,
            static fn (string $message) => self::note($c, $message),
        ));

        // In register(): Folio adds its field types' block rules to the instance in its boot.
        self::registerValueChecks($container);
    }

    public function boot(Container $container): void
    {
        // The defaults, where no provider (the app's, Folio) bound its own in register().
        if (!$container->bound(BlockLocale::class)) {
            $container->singleton(BlockLocale::class, static fn (Container $c): BlockLocale => new DefaultBlockLocale($c->get(Locales::class)));
        }
        if (!$container->bound(BlockTextCleaner::class)) {
            $container->singleton(BlockTextCleaner::class, static fn (): BlockTextCleaner => new DefaultBlockTextCleaner());
        }
        if (!$container->bound(BlockPreviewMarks::class)) {
            $container->singleton(BlockPreviewMarks::class, static fn (): BlockPreviewMarks => new DefaultBlockPreviewMarks());
        }

        self::bootPageLayouts($container);
        self::bindApi($container);

        // In boot: the cache file follows config that a provider registered after this one may set.
        $container->get(CacheRegistry::class)->register(new FileCache('blocks', 'Blocks', $container->get(BlocksConfig::class)->cacheFile));
        $container->get(CacheRegistry::class)->register(new FileCache('layouts', 'Page layouts', $container->get(BlocksConfig::class)->layoutsCacheFile));

        if ($container->has(TemplateEngineInterface::class)) {
            // blocks(items, preview = false): a blocks field rendered through its blocks' components.
            $container->get(TemplateEngineInterface::class)->addFunction(new TemplateFunctionDefinition(
                name: 'blocks',
                callable: static fn (mixed $blocks, bool $preview = false): string => $container->get(BlockRenderer::class)->render($blocks, $preview),
                isSafe: true,
            ));
        }
    }

    /**
     * Classes installed packages list in composer.json extra.preflow.blocks (or, with $kind
     * "layouts", extra.preflow.layouts; read from vendor/composer/installed.json through the boot
     * manifest). Installing the package is enough.
     *
     * @param 'blocks'|'layouts' $kind
     * @return list<class-string>
     */
    public static function packageClasses(Application $app, string $kind = 'blocks'): array
    {
        $layouts = $kind === 'layouts';
        $attribute = $layouts ? Layout::class : Block::class;
        $classes = [];
        foreach ($app->installedPackages() as $package) {
            $declared = InstalledPackages::extra($package, 'preflow.' . $kind);
            if ($declared === null) {
                continue;
            }
            $what = 'extra.preflow.' . $kind;
            $name = (string) ($package['name'] ?? '?');
            $refuse = static fn (string $message): \RuntimeException => $layouts ? new LayoutConfigException($message) : new BlockConfigException($message);
            if (!is_array($declared)) {
                throw $refuse("Package {$name}: {$what} must be a list of class names.");
            }
            foreach ($declared as $class) {
                $class = is_string($class) ? ltrim($class, '\\') : '';
                if ($class === '' || !class_exists($class)) {
                    throw $refuse("Package {$name}: {$what} lists " . ($class !== '' ? "{$class}, which is not a class" : 'a value that is not a class name') . '.');
                }
                if ((new \ReflectionClass($class))->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF) === []) {
                    throw $refuse("Package {$name}: {$what} lists {$class}, which has no #[" . $attribute . '].');
                }
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * PageLayouts with the app's type registry (the one Folio binds, else the DataManager's), the
     * boot check of every type's "layouts" and the layout rules with the data package. In boot:
     * Folio binds its registry in its register().
     */
    private static function bootPageLayouts(Container $container): void
    {
        $data = $container->bound(DataManager::class) ? $container->get(DataManager::class) : null;
        $types = $container->bound(TypeRegistry::class) ? $container->get(TypeRegistry::class) : $data?->getTypeRegistry();
        if ($types === null) {
            return;
        }
        $container->singleton(PageLayouts::class, static fn (Container $c): PageLayouts => new PageLayouts(
            $c->get(LayoutRegistry::class),
            $types,
            $c->get(BlockRegistry::class),
        ));

        // A "layouts" option that cannot work stops the boot, with or without Folio: checked when the
        // boot manifest is built (and live while none is loaded), as the data package's own checks.
        $manifest = $container->get(Application::class)->bootManifest();
        $manifest->remember('blocks.type_layouts', static function () use ($container, $types): bool {
            (new PageLayouts($container->get(LayoutRegistry::class), $types))->assertTypes();

            return true;
        }, $manifest->isRecording() ? $types->sources() : []);

        if ($data === null) {
            return;
        }
        $data->recordRules()->add(static function (TypeDefinition $def) use ($container, $data): array {
            $layouts = $container->get(PageLayouts::class);
            if ($layouts->forType($def->key) === null) {
                return [];
            }

            return [
                PageLayouts::LAYOUT => [new LayoutChoice($layouts, $data, $def)],
                PageLayouts::AREAS => [AreaContents::forPage($layouts, $def, $container->get(BlockRegistry::class), $data)],
            ];
        });
        $data->recordRules()->add(static function (TypeDefinition $def) use ($container, $data): array {
            $layouts = $container->get(PageLayouts::class);
            $type = $layouts->forType($def->key);

            return $type === null || $type->pinned === [] ? [] : [$type->slugField => [new PinnedSlug($layouts, $data, $def)]];
        }, drafts: true);
    }

    /**
     * What an API controller calls (since 0.92): ContentDescription always, and with page layouts
     * LayoutResource (a page's layout and areas as JSON) and LayoutInput (a body merged under the
     * core rules). In boot: PageLayouts is bound in boot.
     */
    private static function bindApi(Container $container): void
    {
        $container->singleton(ContentDescription::class, static fn (Container $c): ContentDescription => new ContentDescription(
            $c->get(BlockRegistry::class),
            $c->get(LayoutRegistry::class),
            $c->get(AreaLayoutSets::class),
            $c->bound(DataManager::class) ? $c->get(DataManager::class)->fieldKinds() : new FieldKinds(),
            $c->bound(PageLayouts::class) ? $c->get(PageLayouts::class) : null,
            $c->bound(TypeRegistry::class) ? $c->get(TypeRegistry::class) : ($c->bound(DataManager::class) ? $c->get(DataManager::class)->getTypeRegistry() : null),
        ));
        if (!$container->bound(PageLayouts::class)) {
            return;
        }
        $container->singleton(LayoutResource::class, static fn (Container $c): LayoutResource => new LayoutResource(
            $c->get(PageLayouts::class),
            $c->get(BlockRegistry::class),
        ));
        $container->singleton(LayoutInput::class, static fn (Container $c): LayoutInput => new LayoutInput(
            $c->get(PageLayouts::class),
            $c->get(BlockRegistry::class),
            $c->bound(ValidatorFactory::class) ? $c->get(ValidatorFactory::class) : new ValidatorFactory(new RuleFactory()),
        ));
    }

    /**
     * The block value checks (BlockValueChecks), with the data package run on every validated
     * write of a typed record: the values inside its block lists and, for a type with layouts, its
     * placed areas. Folio adds its field types' block rules to the same instance.
     */
    private static function registerValueChecks(Container $container): void
    {
        if (!$container->bound(DataManager::class)) {
            return;
        }
        $data = $container->get(DataManager::class);
        $container->singleton(BlockValueChecks::class, static fn (Container $c): BlockValueChecks => new BlockValueChecks(
            $c->get(BlockRegistry::class),
            $data->fieldKinds(),
            $c->bound(ValidatorFactory::class) ? $c->get(ValidatorFactory::class) : new ValidatorFactory(new RuleFactory()),
            // Bound in boot (with a type registry): resolved when the checks are first built.
            $c->bound(PageLayouts::class) ? $c->get(PageLayouts::class) : null,
            $data->fieldLabels(...),
        ));
        // Built on the first validated write, not at boot (the registry reflects over every block).
        $data->recordRules()->check(static fn (DynamicRecord $record, array $given): array => $container->get(BlockValueChecks::class)->errors($record->getType(), $record->toArray(), $given));
    }

    /** A warning for the site's log (its PSR logger, error_log() without one). */
    private static function note(Container $c, string $message): void
    {
        $logger = 'Psr\Log\LoggerInterface';
        if (interface_exists($logger) && $c->bound($logger)) {
            $c->get($logger)->warning($message);

            return;
        }
        error_log($message);
    }
}
