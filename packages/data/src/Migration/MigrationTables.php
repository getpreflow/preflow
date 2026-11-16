<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

use Preflow\Data\Driver\SqliteDialect;

/**
 * The tables the migrations of some folders make: each migration's up() is run, in the order
 * Migrator runs them, against an empty in-memory SQLite database, and the tables there at the end
 * are theirs (a table a later migration drops is not). Migrations only reach the database through
 * Schema, so this tells the tables Preflow made in a database from those another application
 * shares it with, by name, without reading the database. A migration that fails here is named in
 * failed(); the tables of the others are still found.
 *
 * @since 0.78
 */
final class MigrationTables
{
    /** @var list<string> */
    private array $tables = [];

    /** @var array<string, string> migration file name => why it could not be run */
    private array $failed = [];

    /** @param list<string> $paths migration folders (MigrationRegistry::paths()) */
    public function __construct(array $paths)
    {
        $files = [];
        foreach ($paths as $path) {
            foreach (is_dir($path) ? (glob($path . '/*.php') ?: []) : [] as $file) {
                $files[basename($file)] ??= $file;
            }
        }
        ksort($files, SORT_STRING);
        if ($files === []) {
            return;
        }

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        SqliteDialect::prepare($pdo);
        $schema = new Schema($pdo);
        foreach ($files as $name => $file) {
            try {
                $migration = (static fn (string $file): mixed => require $file)($file);
                if (!$migration instanceof Migration) {
                    throw new \RuntimeException('it does not return a Migration');
                }
                $migration->up($schema);
            } catch (\Throwable $e) {
                $this->failed[(string) $name] = $e->getMessage();
            }
        }
        $this->tables = array_map('strval', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<string> the tables the migrations make, by name */
    public function tables(): array
    {
        return $this->tables;
    }

    /** @return array<string, string> each migration that could not be run here (file name => the error) */
    public function failed(): array
    {
        return $this->failed;
    }
}
