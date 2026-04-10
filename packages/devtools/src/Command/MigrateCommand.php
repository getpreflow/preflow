<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

final class MigrateCommand implements CommandInterface
{
    public function getName(): string { return 'migrate'; }
    public function getDescription(): string { return 'Run pending database migrations'; }

    public function execute(array $args): int
    {
        $configPath = getcwd() . '/config/data.php';
        $migrationsPath = getcwd() . '/migrations';

        if (!is_dir($migrationsPath)) {
            echo "No migrations directory found.\n";
            return 0;
        }

        echo "Running migrations...\n";

        // Load DB config
        if (!file_exists($configPath)) {
            fwrite(STDERR, "Error: config/data.php not found.\n");
            return 1;
        }

        $config = require $configPath;
        $dsn = $config['drivers']['sqlite']['dsn'] ?? $config['drivers']['sqlite']['path'] ?? null;

        if ($dsn === null) {
            fwrite(STDERR, "Error: No SQLite configuration found in config/data.php.\n");
            return 1;
        }

        $pdo = new \PDO(str_starts_with($dsn, 'sqlite:') ? $dsn : "sqlite:{$dsn}");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $migrator = new \Preflow\Data\Migration\Migrator($pdo, $migrationsPath);
        $pending = $migrator->pending();

        if ($pending === []) {
            echo "Nothing to migrate.\n";
            return 0;
        }

        echo count($pending) . " pending migration(s).\n";
        $migrator->migrate();
        echo "Done.\n";
        return 0;
    }
}
