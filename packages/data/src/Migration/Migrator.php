<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

final class Migrator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $migrationsPath,
    ) {
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
     * @return string[] All migration file paths, sorted by name
     */
    private function allFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.php');
        sort($files);

        return $files;
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
