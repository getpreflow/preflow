<?php

declare(strict_types=1);

namespace Preflow\Media;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Data\DataManager;
use Preflow\Data\Locales;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Media\Image\ImageBackends;
use Preflow\Media\Image\ImageWidths;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\Usage\AssetUsage;
use Preflow\View\AssetCollector;

/**
 * The media core's services: config (read and checked now, so a bad private_dir
 * fails at boot), the library with its usage sources, the image tag and the
 * locale it renders alt text in. With SQL storage, `preflow migrate` creates the
 * asset table (the media.table step). Listed in config/providers.php by a site
 * without Folio; Folio registers it itself.
 */
final class MediaServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $app = $container->get(Application::class);
        $config = MediaConfig::fromApp($app, $container->bound(DebugCollector::class) ? $container->get(DebugCollector::class) : null);
        $container->instance(MediaConfig::class, $config);

        // Eager widths (made at upload) and the on-demand allow-list, from config/image.php presets.
        $container->singleton(ImageWidths::class, fn () => ImageWidths::fromConfig(
            (array) $app->config()->get('image.presets', []),
            $config->widths,
        ));
        $container->singleton(AssetUsage::class, fn () => new AssetUsage());
        $container->singleton(MediaLibrary::class, fn (Container $c) => new MediaLibrary(
            $c->get(DataManager::class),
            self::locales($c),
            $config->dir,
            $config->url,
            $config->toArray() + ['warm_widths' => $c->get(ImageWidths::class)->allowed()],
            new VariantGenerator(ImageBackends::detect(), $c->get(ImageWidths::class)->eager(), $config->quality),
            usage: $c->get(AssetUsage::class),
        ));
        $container->singleton(LocaleProvider::class, fn (Container $c) => new ConfigLocaleProvider(
            static fn (): ?string => self::translatorLocale($c),
            (string) $app->config()->get('app.locale', 'en'),
        ));
        $container->singleton(ImageTag::class, fn (Container $c) => new ImageTag(
            $c->get(MediaLibrary::class),
            $c->get(LocaleProvider::class),
            (array) $app->config()->get('image.presets', []),
            $c->get(AssetCollector::class),
            '',
            $c->get(ImageWidths::class),
        ));

        if ($config->storage !== 'json') {
            $container->get(MigrationRegistry::class)->afterMigrate(
                'media.table',
                static fn (): array => (new RecordTableSync($container->get(DataManager::class)))->sync($container->get(MediaLibrary::class)->definition()),
            );
        }
    }

    private static function locales(Container $c): Locales
    {
        try {
            return $c->get(Locales::class);
        } catch (\Throwable) {
            return Locales::single('en');
        }
    }

    private static function translatorLocale(Container $c): ?string
    {
        $translator = 'Preflow\I18n\Translator';
        if (!class_exists($translator) || !$c->bound($translator)) {
            return null;
        }

        return $c->get($translator)->getLocale();
    }
}
