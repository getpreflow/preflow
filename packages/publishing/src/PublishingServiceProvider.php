<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Schema\RecordTables;

/**
 * Publishing: drafts and published snapshots (Publisher), revisions, scheduled publishing
 * (Scheduler, publish:scheduled), share previews (PreviewLinks) and the public reads of
 * LiveContent and PublishedModels, configured by config/publishing.php (PublishingConfig).
 *
 * The Publisher asks PublishingTypes, RecordChecks, LanguageChecks, RecordIndex,
 * RecordAddresses and RecordColumns, the tables (PublishingTables, the `publishing.tables` migrate step) ask
 * RecordColumns, and `publish` asks PublishSkips. RecordWriter and RecordDeleter, the one write
 * and delete path for records, also ask SaveHooks and RecordTypes. A provider (the app's, Folio)
 * binds its own in register(), and boot() binds the plain defaults for the rest.
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
                $c->get(RecordColumns::class),
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
        // The tables of the publishing types on SQL storage: created by `preflow migrate`, listed for the data layer.
        $container->singleton(PublishingTables::class, static fn (Container $c): PublishingTables => new PublishingTables(
            $c->get(DataManager::class),
            $c->get(PublishingTypes::class),
            $c->get(RecordColumns::class),
        ));
        if ($container->bound(RecordTables::class)) {
            $container->get(RecordTables::class)->add('publishing', static fn (): array => $container->get(PublishingTables::class)->tables());
        }
        if ($container->bound(MigrationRegistry::class)) {
            $container->get(MigrationRegistry::class)->afterMigrate('publishing.tables', static fn (): array => $container->get(PublishingTables::class)->sync());
        }
        // The one write and delete path for records (an admin, a translation run, an app's own code).
        $container->singleton(RecordWriter::class, static fn (Container $c): RecordWriter => new RecordWriter(
            $c->get(DataManager::class),
            $c->get(Publisher::class),
            $c->get(RecordChecks::class),
            $c->get(SaveHooks::class),
            $c->get(RecordTypes::class),
            $c->get(RecordIndex::class),
            $c->get(RecordAddresses::class),
            null,
            // psr/log is suggested, not required: a bound logger gets RecordWriter's warnings.
            interface_exists(\Psr\Log\LoggerInterface::class) && $c->bound(\Psr\Log\LoggerInterface::class) ? $c->get(\Psr\Log\LoggerInterface::class) : null,
        ));
        $container->singleton(RecordDeleter::class, static fn (Container $c): RecordDeleter => new RecordDeleter(
            $c->get(DataManager::class),
            $c->get(Publisher::class),
            $c->get(SaveHooks::class),
            $c->get(RecordIndex::class),
            $c->get(RecordAddresses::class),
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
        if (!$container->bound(RecordColumns::class)) {
            $container->singleton(RecordColumns::class, static fn (): RecordColumns => new NoRecordColumns());
        }
        if (!$container->bound(PublishSkips::class)) {
            $container->singleton(PublishSkips::class, static fn (): PublishSkips => new NoPublishSkips());
        }
        if (!$container->bound(SaveHooks::class)) {
            $container->singleton(SaveHooks::class, static fn (): SaveHooks => new NoSaveHooks());
        }
        if (!$container->bound(RecordTypes::class)) {
            $container->singleton(RecordTypes::class, static fn (): RecordTypes => new NoRecordTypes());
        }
    }
}
