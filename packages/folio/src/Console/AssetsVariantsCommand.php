<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\DevTools\Command\CommandInterface;
use Preflow\Media\Console\VariantsCommand;

/**
 * @deprecated since 0.34, use "media:variants" (Preflow\Media\Console\VariantsCommand).
 * folio:assets:variants [--id=<id>] [--all] still works for one release: it prints a
 * note and runs media:variants.
 */
final class AssetsVariantsCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'folio:assets:variants'; }
    public function getDescription(): string { return 'Deprecated: use media:variants'; }

    public function execute(array $args): int
    {
        echo "Note: \"folio:assets:variants\" is deprecated; use \"media:variants\".\n";

        return (new VariantsCommand($this->appFactory))->execute($args);
    }
}
