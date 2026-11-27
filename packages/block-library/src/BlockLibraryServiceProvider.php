<?php

declare(strict_types=1);

namespace Preflow\BlockLibrary;

use Preflow\Blocks\Layouts\AreaLayoutSet;
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\I18n\Translator;

/**
 * The block library for a site: the column layout set "columns" (ColumnLayouts, from
 * config/blocks.php) that the columns block's areas field takes its layouts from, and the texts of
 * the group "block-library" (en, de, fr).
 *
 * Installing the package registers its blocks (composer.json extra.preflow.blocks); a site lists
 * this provider in config/providers.php itself, with or without Folio. Without it the block
 * registry refuses the columns block, naming the set no package registers.
 *
 * @since 0.92.0
 */
final class BlockLibraryServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $app = $container->get(Application::class);

        // One instance, whoever binds it first (BlocksServiceProvider binds it the same way).
        if (!$container->bound(AreaLayoutSets::class)) {
            $container->instance(AreaLayoutSets::class, new AreaLayoutSets());
        }
        // Built on first use (the block registry's check of the areas field, the editor, a render).
        $container->get(AreaLayoutSets::class)->add(ColumnLayouts::SET, static function () use ($app): AreaLayoutSet {
            $config = $app->configFile('blocks');

            return ColumnLayouts::fromConfig(is_array($config) ? $config : []);
        });
    }

    public function boot(Container $container): void
    {
        // Declared in composer.json (extra.preflow.translations); this covers apps whose Composer metadata doesn't list the package.
        if ($container->has(Translator::class)) {
            $container->get(Translator::class)->addDefaultsIfMissing('block-library', self::langPath(), 'public');
        }
    }

    /** The package's lang directory (lang/{en,de,fr}/block-library.php). */
    public static function langPath(): string
    {
        return dirname(__DIR__) . '/lang';
    }
}
