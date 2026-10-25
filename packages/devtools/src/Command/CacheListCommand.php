<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;
use Preflow\Core\Cache\Bytes;
use Preflow\Core\Cache\CacheRegistry;

/** Lists every registered cache with its entry count and size. */
final class CacheListCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'cache:list'; }
    public function getDescription(): string { return 'List registered caches with entries and size'; }

    public function execute(array $args): int
    {
        try {
            $sources = $this->app()->container()->get(CacheRegistry::class)->all();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
            return 1;
        }

        if ($sources === []) {
            echo "No caches registered.\n";
            return 0;
        }

        $rows = [['Key', 'Label', 'Entries', 'Size']];
        foreach ($sources as $source) {
            $stats = $source->stats();
            $rows[] = [$source->key(), $source->label(), (string) $stats->entries, Bytes::human($stats->bytes)];
        }

        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
            }
        }
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $i => $cell) {
                $cells[] = $i === count($row) - 1 ? $cell : str_pad($cell, $widths[$i]);
            }
            echo implode('  ', $cells) . "\n";
        }

        return 0;
    }

    private function app(): Application
    {
        if ($this->appFactory !== null) {
            return ($this->appFactory)();
        }

        $app = Application::create(getcwd() ?: '.');
        $app->boot();

        return $app;
    }
}
