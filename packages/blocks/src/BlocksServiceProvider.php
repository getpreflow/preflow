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
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Blocks\Layouts\Layout;
use Preflow\Blocks\Layouts\LayoutConfigException;
use Preflow\Blocks\Layouts\LayoutRegistry;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\Locales;
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
