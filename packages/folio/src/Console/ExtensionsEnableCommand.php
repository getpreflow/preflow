<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\DevTools\Command\CommandInterface;

/** Enables an extension, or one part of an enabled one, in config/folio/extensions.json. Install steps run with folio:extensions:apply. */
final class ExtensionsEnableCommand extends ExtensionsToggleCommand implements CommandInterface
{
    public function getName(): string { return 'folio:extensions:enable'; }
    public function getDescription(): string { return 'Enable a Folio extension (<name> [--part=<id>])'; }

    protected function on(): bool
    {
        return true;
    }
}
