<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

final class Migrator
{
    /** @var list<string> */
    private readonly array $migrationsPaths;

    public function __construct(
        private readonly \PDO $pdo,
        string|array $migrationsPaths,
    ) {
        $this->migrationsPaths = is_array($migrationsPaths) ? array_values($migrationsPaths) : [$migrationsPaths];
        $this->ensureMigrationsTable();
    }

    public function migrate(): void
    {
        $pending = $this->pending();

        foreach ($pending as $file) {
            $migration = require $file;

            if (!$migration instanceof Migration) {
                throw new \RuntimeException("Migration file [{$file}] must return a Migration instance.");
            }

            $schema = new Schema($this->pdo);
            $migration->up($schema);

            $this->recordMigration(basename($file));
        }
    }

    /**
     * @return string[] Full paths to pending migration files
     */
    public function pending(): array
    {
        $all = $this->allFiles();
        $ran = $this->ranMigrations();

        return array_values(array_filter($all, function (string $file) use ($ran) {
            return !in_array(basename($file), $ran, true);
        }));
    }

    /**
     * @return string[] All migration file paths across all directories, sorted by name
     */
    private function allFiles(): array
    {
        $byName = [];

        foreach ($this->migrationsPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            foreach (glob($path . '/*.php') as $file) {
                $name = basename($file);
                if (isset($byName[$name])) {
                    throw new \RuntimeException("Duplicate migration name [{$name}]: {$byName[$name]} and {$file}.");
                }
                $byName[$name] = $file;
            }
        }

        ksort($byName, SORT_STRING);

        return array_values($byName);
    }

    /**
     * @return string[] Basenames of already-run migrations
     */
    private function ranMigrations(): array
    {
        $stmt = $this->pdo->query('SELECT migration FROM preflow_migrations ORDER BY migration');

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function recordMigration(string $name): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO preflow_migrations (migration, ran_at) VALUES (?, ?)');
        $stmt->execute([$name, date('Y-m-d H:i:s')]);
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS preflow_migrations (
            migration TEXT PRIMARY KEY,
            ran_at TEXT NOT NULL
        )');
    }
}
