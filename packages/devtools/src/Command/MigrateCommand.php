<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Migration\Migrator;

/** Boots the app, runs every registered migration path, then the post-migrate steps. */
final class MigrateCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'migrate'; }
    public function getDescription(): string { return 'Run pending database migrations (--storage=<driver>, --pretend)'; }

    public function execute(array $args): int
    {
        $storage = null;
        $pretend = false;
        foreach ($args as $arg) {
            if ($arg === '--pretend') {
                $pretend = true;
            } elseif (str_starts_with($arg, '--storage=')) {
                $storage = substr($arg, strlen('--storage='));
            }
        }

        try {
            $app = $this->app();
            $container = $app->container();

            // Without --storage, a site with no SQL storage (JSON only, or no config/data.php)
            // has nothing to migrate: that is success, so a shared deploy step can always run it.
            // (The DataManager is bound only with config/data.php; has() is true for any class.)
            $dm = is_file($app->basePath('config/data.php')) ? $container->get(DataManager::class) : null;
            if ($storage === null && !$this->hasSqlStorage($dm)) {
                echo "Nothing to migrate: no SQL storage configured.\n";
                return 0;
            }
            if ($dm === null) {
                throw new \RuntimeException('No data configuration found (config/data.php).');
            }

            $storage ??= 'sqlite';
            $driver = $dm->driver($storage);
            if (!$driver instanceof PdoDriver) {
                throw new \RuntimeException("Storage driver [{$storage}] is not a SQL driver; nothing to migrate.");
            }

            $registry = $container->get(MigrationRegistry::class);
            $migrator = new Migrator($driver->pdo(), $registry->paths());
            $pending = $migrator->pending();

            if ($pending === []) {
                echo "Nothing to migrate.\n";
            } else {
                echo count($pending) . " pending migration(s).\n";
                if ($pretend) {
                    foreach ($pending as $file) {
                        echo '  ' . basename($file) . "\n";
                    }
                } else {
                    $migrator->migrate();
                }
            }

            if (!$pretend) {
                foreach ($registry->steps() as $name => $step) {
                    foreach ($step() as $line) {
                        echo "[{$name}] {$line}\n";
                    }
                }
                echo "Done.\n";
            }

            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    private function hasSqlStorage(?DataManager $dm): bool
    {
        foreach ($dm?->driverNames() ?? [] as $name) {
            if ($dm->driver($name) instanceof PdoDriver) {
                return true;
            }
        }

        return false;
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
