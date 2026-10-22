<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Extension\Extension;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\ExtensionState;
use Preflow\Folio\Storage\TableSync;

/**
 * Run on every deploy: syncs Folio's tables (as `preflow migrate` does), then runs install()
 * of each enabled extension whose installed version here differs from its version, once.
 */
final class ExtensionsApplyCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'folio:extensions:apply'; }
    public function getDescription(): string { return 'Sync Folio tables and install enabled extensions (once per version)'; }

    public function execute(array $args): int
    {
        $c = $this->app()->container();
        $lines = $c->get(TableSync::class)->sync();
        foreach ($lines as $line) {
            echo $line . "\n";
        }

        $state = $c->get(ExtensionState::class);
        $did = $lines !== [];
        foreach ($c->get(ExtensionRegistry::class)->enabled() as $definition) {
            $installed = $state->installedVersion($definition->name);
            if ($installed === $definition->version) {
                continue;
            }
            $extension = $c->get($definition->class);
            assert($extension instanceof Extension);
            $extension->install($c);
            $state->markInstalled($definition->name, $definition->version);
            echo "installed {$definition->name} {$definition->version}" . ($installed === null ? '' : " (was {$installed})") . "\n";
            $did = true;
        }
        if (!$did) {
            echo "nothing to do\n";
        }

        return 0;
    }
}
