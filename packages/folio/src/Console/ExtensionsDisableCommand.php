<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\DevTools\Command\CommandInterface;

/** Disables an extension, or one part of it, in config/folio/extensions.json. Its data stays. */
final class ExtensionsDisableCommand extends ExtensionsToggleCommand implements CommandInterface
{
    public function getName(): string { return 'folio:extensions:disable'; }
    public function getDescription(): string { return 'Disable a Folio extension, keeping its data (<name> [--part=<id>])'; }

    protected function on(): bool
    {
        return false;
    }
}
