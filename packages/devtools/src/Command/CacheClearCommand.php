<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;
use Preflow\Core\Cache\Bytes;
use Preflow\Core\Cache\CacheCleared;
use Preflow\Core\Cache\CacheRegistry;

/** Clears registered caches: one by key, or all of them. Unregistered files are never touched. */
final class CacheClearCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'cache:clear'; }
    public function getDescription(): string { return 'Clear a registered cache by key (cache:clear <key>, --all for every cache)'; }

    public function execute(array $args): int
    {
        $all = in_array('--all', $args, true);
        $keys = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')));

        try {
            $registry = $this->app()->container()->get(CacheRegistry::class);

            if ($keys !== []) {
                foreach ($keys as $key) {
                    if ($registry->get($key) === null) {
                        $known = implode(', ', array_map(static fn ($s): string => $s->key(), $registry->all()));
                        echo "Unknown cache: {$key} (known: {$known})\n";
                        return 1;
                    }
                }
                foreach ($keys as $key) {
                    $this->report($key, $registry->get($key)->clear());
                }
                return 0;
            }

            if (!$all) {
                echo "Note: use \"cache:clear --all\"; the bare form will require it in a later release.\n";
            }
            foreach ($registry->clearAll() as $key => $cleared) {
                $this->report($key, $cleared);
            }

            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    private function report(string $key, CacheCleared $cleared): void
    {
        echo "Cleared {$key}: {$cleared->entries} file(s), " . Bytes::human($cleared->bytes) . ".\n";
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
