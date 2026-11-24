<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

/**
 * Folio's CLI commands. They register themselves from extra.preflow.commands in Folio's
 * composer.json, so an app needs no config/commands.php line. This class stays for apps that
 * list it anyway (registering a name twice is harmless):
 *
 *     return [...\Preflow\Folio\Console\FolioCommands::all()];
 */
final class FolioCommands
{
    /** @return list<class-string<\Preflow\DevTools\Command\CommandInterface>> */
    public static function all(): array
    {
        return [
            ReviewIndexCommand::class,
            AssetsVariantsCommand::class,
            AssetsImportCommand::class,
            ExportCommand::class,
            ImportCommand::class,
            PullCommand::class,
            ExtensionsListCommand::class,
            ExtensionsEnableCommand::class,
            ExtensionsDisableCommand::class,
            ExtensionsApplyCommand::class,
            ExtensionsCheckCommand::class,
            ExtensionsUninstallCommand::class,
            InstallCommand::class,
        ];
    }
}
