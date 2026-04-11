<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

final class SeedCommand implements CommandInterface
{
    public function getName(): string { return 'db:seed'; }
    public function getDescription(): string { return 'Run database seeders'; }

    public function execute(array $args): int
    {
        $seederDir = getcwd() . '/app/Seeds';
        if (!is_dir($seederDir)) {
            echo "No seeders directory found (app/Seeds/).\n";
            return 0;
        }

        // Boot a minimal data layer
        $configPath = getcwd() . '/config/data.php';
        if (!file_exists($configPath)) {
            fwrite(STDERR, "Error: config/data.php not found.\n");
            return 1;
        }

        $dataConfig = require $configPath;
        $sqlitePath = $dataConfig['drivers']['sqlite']['path'] ?? null;
        if ($sqlitePath === null) {
            fwrite(STDERR, "Error: No SQLite config found.\n");
            return 1;
        }

        $dbDir = dirname($sqlitePath);
        if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);

        $dsn = str_starts_with($sqlitePath, 'sqlite:') ? $sqlitePath : 'sqlite:' . $sqlitePath;
        $pdo = new \PDO($dsn);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $drivers = ['sqlite' => new \Preflow\Data\Driver\SqliteDriver($pdo), 'default' => new \Preflow\Data\Driver\SqliteDriver($pdo)];
        $dataManager = new \Preflow\Data\DataManager($drivers);

        echo "Running seeders...\n";

        foreach (glob($seederDir . '/*.php') as $file) {
            $className = 'App\\Seeds\\' . basename($file, '.php');
            if (class_exists($className)) {
                $seeder = new $className();
                $seeder->run($dataManager);
                echo "  Seeded: " . basename($file, '.php') . "\n";
            }
        }

        echo "Done.\n";
        return 0;
    }
}
