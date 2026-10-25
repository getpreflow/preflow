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
use Preflow\Core\Routing\RouterInterface;
use Preflow\Media\Access\DenyAllPrivateFileAccess;
use Preflow\Media\Access\PrivateFileAccess;
use Preflow\Media\Http\PrivateFileController;
use Preflow\Media\Http\VariantController;
use Preflow\Media\Image\ColorManagement;
use Preflow\Media\Image\ImageBackends;
use Preflow\Media\Image\ImageWidths;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\Preview\PdfPreviewRenderer;
use Preflow\Media\Preview\PreviewRenderers;
use Preflow\Media\Usage\AssetUsage;
use Preflow\Media\Usage\LazyReferenceSource;
use Preflow\Media\Routing\MediaRoutes;
use Preflow\Media\Usage\PosterReferences;
use Preflow\Routing\Router;
use Preflow\View\AssetCollector;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * The media core's services: config (read and checked now, so a bad private_dir
 * fails at boot), the library with its usage sources, the image tag and the
 * locale it renders alt text in. With SQL storage, `preflow migrate` creates the
 * asset table (the media.table step). At boot it adds its routes (private files,
 * missing variants) and the template functions media_image(), media_url() and
 * media_preview_url(). PDF previews come from Ghostscript (PdfPreviewRenderer), as the
 * fallback of the shared PreviewRenderers, so a site's own renderer wins.
 * Private files are refused until something binds PrivateFileAccess (Folio does).
 * Listed in config/providers.php by a site without Folio; Folio registers it itself.
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
        // Usage sources: Folio and sites register theirs on the shared AssetUsage (one per
        // container, also when a provider listed before this one asked for it first); the
        // library's own poster references are asked last, so poster rows follow record rows.
        // Resolved per check: Folio re-binds PosterReferences to link to its library screen.
        $container->singleton(PosterReferences::class, fn (Container $c) => new PosterReferences(static fn (): MediaLibrary => $c->get(MediaLibrary::class)));
        $container->get(AssetUsage::class)->register(
            new LazyReferenceSource(PosterReferences::NAME, static fn (): PosterReferences => $container->get(PosterReferences::class)),
            last: true,
        );
        $colors = new ColorManagement($config->colorProfileCmyk);
        $container->get(PreviewRenderers::class)->register(new PdfPreviewRenderer($colors, $config->ghostscript), fallback: true);
        $container->singleton(MediaLibrary::class, fn (Container $c) => new MediaLibrary(
            $c->get(DataManager::class),
            self::locales($c),
            $config->dir,
            $config->url,
            $config->toArray() + ['warm_widths' => $c->get(ImageWidths::class)->allowed()],
            new VariantGenerator(ImageBackends::detect(), $c->get(ImageWidths::class)->eager(), $config->quality, $colors),
            usage: $c->get(AssetUsage::class),
            previews: $c->get(PreviewRenderers::class),
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

        // Nobody downloads a private file until Folio or the site binds its own rules.
        if (!$container->bound(PrivateFileAccess::class)) {
            $container->singleton(PrivateFileAccess::class, fn () => new DenyAllPrivateFileAccess());
        }
        $container->bind(VariantController::class, fn (Container $c) => new VariantController(
            $c->get(MediaLibrary::class),
            $c->get(ImageWidths::class),
        ));
        $container->bind(PrivateFileController::class, fn (Container $c) => new PrivateFileController(
            $c->get(MediaLibrary::class),
            $c->get(PrivateFileAccess::class),
        ));

        if ($config->storage !== 'json') {
            $container->get(MigrationRegistry::class)->afterMigrate(
                'media.table',
                static fn (): array => (new RecordTableSync($container->get(DataManager::class)))->sync($container->get(MediaLibrary::class)->definition()),
            );
        }
    }

    public function boot(Container $container): void
    {
        if ($container->has(TemplateEngineInterface::class)) {
            $engine = $container->get(TemplateEngineInterface::class);
            // media_image(id, preset?, options?): the <img>/<picture> (or a link for a document); the ImageTag service's output.
            $engine->addFunction(new TemplateFunctionDefinition(
                name: 'media_image',
                callable: fn (mixed $value, ?string $preset = null, array $options = []): string => $container->get(ImageTag::class)->render($value, $preset, $options),
                isSafe: true,
            ));
            // media_url(id): an asset's URL ('' without one), e.g. a PDF download link.
            $engine->addFunction(new TemplateFunctionDefinition(
                name: 'media_url',
                callable: fn (mixed $value = null): string => $container->get(ImageTag::class)->url($value),
            ));
            // media_preview_url(id): a document's preview URL ('' without one; a private asset's is gated).
            $engine->addFunction(new TemplateFunctionDefinition(
                name: 'media_preview_url',
                callable: fn (mixed $value = null): string => $container->get(ImageTag::class)->previewUrl($value),
            ));
        }

        // After the app's routes; on a Folio site before Folio's (Folio registers media first, so media boots first).
        if ($container->has(RouterInterface::class)) {
            $router = $container->get(RouterInterface::class);
            if ($router instanceof Router) {
                MediaRoutes::register($router->getCollection(), $container->get(MediaConfig::class));
            }
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
