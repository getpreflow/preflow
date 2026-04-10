<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

final class RoutesListCommand implements CommandInterface
{
    public function getName(): string { return 'routes:list'; }
    public function getDescription(): string { return 'List all registered routes'; }

    public function execute(array $args): int
    {
        $pagesDir = getcwd() . '/app/pages';

        echo "Registered Routes\n";
        echo str_repeat('─', 70) . "\n";
        echo str_pad('Method', 8) . str_pad('Pattern', 35) . str_pad('Mode', 12) . "Handler\n";
        echo str_repeat('─', 70) . "\n";

        if (is_dir($pagesDir)) {
            $scanner = new \Preflow\Routing\FileRouteScanner($pagesDir);
            foreach ($scanner->scan() as $entry) {
                echo str_pad($entry->method, 8)
                    . str_pad($entry->pattern, 35)
                    . str_pad($entry->mode->value, 12)
                    . $entry->handler . "\n";
            }
        }

        echo str_repeat('─', 70) . "\n";
        return 0;
    }
}
