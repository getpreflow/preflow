<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

/**
 * Folio's CLI commands, for an app's config/commands.php:
 *
 *     return [...\Preflow\Folio\Console\FolioCommands::all()];
 */
final class FolioCommands
{
    /** @return list<class-string<\Preflow\DevTools\Command\CommandInterface>> */
    public static function all(): array
    {
        return [
            PublishScheduledCommand::class,
            PublishCommand::class,
            AssetsVariantsCommand::class,
            AssetsImportCommand::class,
            ExportCommand::class,
            ImportCommand::class,
        ];
    }
}
