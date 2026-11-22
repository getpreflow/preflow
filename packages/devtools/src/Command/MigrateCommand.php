<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\PdoConnection;
use Preflow\Data\RemembersColumns;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Migration\Migrator;

/**
 * Boots the app, runs every registered migration path, then the post-migrate steps. A site
 * without SQL storage runs only the steps registered for any storage (sqlOnly: false). Exits 1
 * when a step refused something (a line starting with "refused:"), after every step has run.
 * --pretend lists the pending migrations and runs the read-only checks
 * (MigrationRegistry::check(): what the steps would refuse), changing nothing.
 */
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

            // Without --storage, a site with no SQL storage has no schema to migrate: that is
            // success, so a shared deploy step can always run it. A JSON-only site still runs the
            // steps registered for any storage (sqlOnly: false); without config/data.php there is
            // no DataManager for them. (The DataManager is bound only with config/data.php; has()
            // is true for any class.)
            $dm = is_file($app->basePath('config/data.php')) ? $container->get(DataManager::class) : null;
            if ($storage === null && !$this->hasSqlStorage($dm)) {
                if ($dm === null) {
                    echo "Nothing to migrate: no SQL storage configured.\n";
                    return 0;
                }
                echo "No SQL storage configured: no schema migrations.\n";
                $registry = $container->get(MigrationRegistry::class);
                if ($pretend) {
                    return $this->refused($this->runSteps($registry->checks(sql: false)));
                }
                $refused = $this->runSteps($registry->steps(sql: false));
                echo "Done.\n";
                return $this->refused($refused);
            }
            if ($dm === null) {
                throw new \RuntimeException('No data configuration found (config/data.php).');
            }

            $storage ??= self::defaultStorage($dm)
                ?? throw new \RuntimeException('Several SQL storages are configured; choose one with --storage=<driver>.');
            $driver = $dm->driver($storage);
            if (!$driver instanceof PdoConnection) {
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
                    // The migrations went past the data layer: what it remembers of the columns is stale.
                    $dm->forgetColumns();
                    if ($driver instanceof RemembersColumns) {
                        $driver->forgetColumns();
                    }
                }
            }

            $registry->ranOn($storage);
            if ($pretend) {
                // The read-only checks: what the steps would refuse, nothing changed.
                return $this->refused($this->runSteps($registry->checks(sql: true)));
            }
            $refused = $this->runSteps($registry->steps(sql: true));
            echo "Done.\n";

            return $this->refused($refused);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * The storage to migrate without --storage: the default storage when it is SQL, else the
     * one SQL storage the site has (aliases of it count once); null when there are several.
     */
    public static function defaultStorage(DataManager $dm): ?string
    {
        if (in_array('default', $dm->driverNames(), true) && $dm->driver('default') instanceof PdoConnection) {
            return 'default';
        }
        $sql = [];
        foreach ($dm->driverNames() as $name) {
            $driver = $dm->driver($name);
            if ($driver instanceof PdoConnection && !in_array($driver, $sql, true)) {
                $sql[$name] = $driver;
            }
        }

        return count($sql) === 1 ? array_key_first($sql) : null;
    }

    /**
     * Runs every step, printing its lines.
     *
     * @param array<string, \Closure(): list<string>> $steps
     * @return list<string> the steps that refused something (a line starting with "refused:", such as
     *     a text column whose values would collide under the text rule, data README "Exact comparisons";
     *     a table the change-date backfill could not finish, since 0.84)
     */
    private function runSteps(array $steps): array
    {
        $refused = [];
        foreach ($steps as $name => $step) {
            foreach ($step() as $line) {
                echo "[{$name}] {$line}\n";
                if (str_starts_with($line, 'refused:')) {
                    $refused[$name] = true;
                }
            }
        }

        return array_keys($refused);
    }

    /**
     * Exit code 1 after every step ran when one refused something (the text rule's duplicate
     * values, a table the change-date backfill could not finish), with the closing line naming
     * the steps; else 0.
     *
     * @param list<string> $refused the steps that refused (runSteps())
     */
    private function refused(array $refused): int
    {
        if ($refused === []) {
            return 0;
        }
        echo 'Some steps refused something (see "refused:" above); what they refused was left as it is. Fix what each line names (a group of values the text rule takes for one: rename one record of each group), then run migrate again. (' . implode(', ', $refused) . ")\n";

        return 1;
    }

    private function hasSqlStorage(?DataManager $dm): bool
    {
        foreach ($dm?->driverNames() ?? [] as $name) {
            if ($dm->driver($name) instanceof PdoConnection) {
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
