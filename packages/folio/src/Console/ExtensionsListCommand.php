<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\ExtensionState;

/** The discovered extensions: version, enabled, installed version here, and each part on or off. */
final class ExtensionsListCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'folio:extensions:list'; }
    public function getDescription(): string { return 'List Folio extensions, their parts and installed versions'; }

    public function execute(array $args): int
    {
        $c = $this->app()->container();
        $rows = $c->get(ExtensionState::class)->overview($c->get(ExtensionRegistry::class));
        if ($rows === []) {
            echo "No extensions found (app/Folio/*/Extension.php, Composer extra.folio.extension).\n";

            return 0;
        }
        foreach ($rows as $row) {
            echo "{$row['name']} {$row['version']}  " . ($row['enabled'] ? 'enabled' : 'disabled') . '  installed: ' . ($row['installed'] ?? 'none') . "\n";
            foreach ($row['parts'] as $part) {
                echo "  {$part['id']}  {$part['label']}  " . ($part['enabled'] ? 'on' : 'off') . "\n";
            }
        }

        return 0;
    }
}
