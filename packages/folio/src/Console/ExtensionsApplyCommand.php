<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Extension\Extension;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\ExtensionState;
use Preflow\Folio\Storage\TableSync;

/**
 * Run on every deploy: syncs Folio's tables (as `preflow migrate` does), then, for each enabled
 * extension, runs install() once when its installed version here differs from its version, and
 * seed() every run (silently: a run that installs nothing still says "nothing to do").
 */
final class ExtensionsApplyCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'folio:extensions:apply'; }
    public function getDescription(): string { return 'Sync Folio tables, install enabled extensions once per version, seed every run'; }

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
            $extension = $c->get($definition->class);
            assert($extension instanceof Extension);
            $installed = $state->installedVersion($definition->name);
            if ($installed !== $definition->version) {
                $extension->install($c);
                $state->markInstalled($definition->name, $definition->version);
                echo "installed {$definition->name} {$definition->version}" . ($installed === null ? '' : " (was {$installed})") . "\n";
                $did = true;
            }
            $extension->seed($c);
        }
        if (!$did) {
            echo "nothing to do\n";
        }

        return 0;
    }
}
