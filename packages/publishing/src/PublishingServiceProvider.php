<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;

/**
 * Publishing: drafts and published snapshots (Publisher), revisions, scheduled publishing
 * (Scheduler, publish:scheduled), share previews (PreviewLinks) and the public reads of
 * LiveContent and PublishedModels, configured by config/publishing.php (PublishingConfig).
 *
 * The Publisher asks PublishingTypes, RecordChecks, LanguageChecks, RecordIndex and
 * RecordAddresses: a provider (the app's, Folio) binds its own in register(), and boot() binds
 * the plain defaults for the rest.
 *
 * Listed in config/providers.php by a site without Folio; Folio registers it itself.
 */
final class PublishingServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $app = $container->get(Application::class);

        $container->singleton(PublishingConfig::class, static fn (): PublishingConfig => PublishingConfig::fromApp($app));

        $container->singleton(Publisher::class, static function (Container $c): Publisher {
            $config = $c->get(PublishingConfig::class);

            return new Publisher(
                $c->get(DataManager::class),
                $c->get(PublishingTypes::class),
                $c->get(RecordChecks::class),
                $config->revisions,
                null,
                $c->get(LanguageChecks::class),
                $config->completeLanguages,
                $c->get(RecordIndex::class),
                $c->get(RecordAddresses::class),
            );
        });
        $container->singleton(Scheduler::class, static fn (Container $c): Scheduler => new Scheduler(
            $c->get(Publisher::class),
            $c->get(PublishingTypes::class),
            $c->get(DataManager::class),
        ));
        $container->singleton(PublishedCopies::class, static fn (Container $c): PublishedCopies => new PublishedCopies(
            $c->get(DataManager::class),
            $c->get(Publisher::class),
        ));
        // Request-scoped: public renders read published snapshots; an admin switches it to working copies.
        $container->singleton(LiveContent::class, static fn (Container $c): LiveContent => new LiveContent(
            $c->get(DataManager::class),
            $c->get(PublishingTypes::class),
        ));
        // App code's typed reads of what the public site sees of a model class (its published copies, as LiveContent decides).
        $container->singleton(PublishedModels::class, static fn (Container $c): PublishedModels => new PublishedModels(
            $c->get(DataManager::class),
            $c->get(LiveContent::class),
        ));
        // Signed with the app key: without a real one, share previews are off (PreviewLinks::enabled()).
        $container->singleton(PreviewLinks::class, static fn (Container $c): PreviewLinks => new PreviewLinks(
            (string) $app->config()->get('app.key', ''),
            $c->get(PublishingConfig::class)->previewTtl,
        ));
    }

    public function boot(Container $container): void
    {
        // Read at boot: a bad 'revisions' stops the boot, not the first publish.
        $container->get(PublishingConfig::class);

        // The defaults, where no provider (the app's, Folio) bound its own in register().
        if (!$container->bound(PublishingTypes::class)) {
            $container->singleton(PublishingTypes::class, static fn (Container $c): PublishingTypes => new SchemaPublishingTypes($c->get(DataManager::class)));
        }
        if (!$container->bound(RecordChecks::class)) {
            $container->singleton(RecordChecks::class, static fn (): RecordChecks => new NoRecordChecks());
        }
        if (!$container->bound(LanguageChecks::class)) {
            $container->singleton(LanguageChecks::class, static fn (): LanguageChecks => new NoLanguageChecks());
        }
        if (!$container->bound(RecordIndex::class)) {
            $container->singleton(RecordIndex::class, static fn (): RecordIndex => new NoRecordIndex());
        }
        if (!$container->bound(RecordAddresses::class)) {
            $container->singleton(RecordAddresses::class, static fn (): RecordAddresses => new NoRecordAddresses());
        }
    }
}
