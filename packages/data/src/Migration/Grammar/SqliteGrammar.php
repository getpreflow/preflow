<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

/** The DDL text here is pinned by SqliteDdlCharacterisationTest: existing sites must not see changes. */
final class SqliteGrammar implements SchemaGrammar
{
    public function quote(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    public function columnType(string $kind, ?int $length = null): string
    {
        return match ($kind) {
            'integer', 'boolean' => 'INTEGER',
            default => 'TEXT',
        };
    }

    public function createTable(string $table, array $columns): string
    {
        $parts = [];

        foreach ($columns as $col) {
            $def = $this->quote($col['name']) . ' ' . $this->columnType($col['kind'], $col['length']);

            if ($col['primary']) {
                $def .= ' PRIMARY KEY';
            }

            if (!$col['nullable'] && !$col['primary']) {
                $def .= ' NOT NULL';
            }

            $parts[] = $def;
        }

        return 'CREATE TABLE ' . $this->quote($table) . " (\n    " . implode(",\n    ", $parts) . "\n)";
    }

    public function createRecordTable(string $table, string $idField, array $columns): string
    {
        $defs = array_map(fn (string $c): string => $this->quote($c) . ' TEXT', $columns);

        return 'CREATE TABLE ' . $this->quote($table) . ' (' . $this->quote($idField) . ' TEXT PRIMARY KEY, '
            . implode(', ', $defs) . ')';
    }

    public function createIndex(string $table, string $column): string
    {
        return 'CREATE INDEX ' . $this->quote("idx_{$table}_{$column}")
            . ' ON ' . $this->quote($table) . ' (' . $this->quote($column) . ')';
    }

    public function dropTable(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quote($table);
    }

    public function hasTable(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    public function columns(\PDO $pdo, string $table): array
    {
        $rows = $pdo->query('PRAGMA table_info(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    public function addColumn(string $table, string $column, ?string $type = null): string
    {
        return 'ALTER TABLE ' . $this->quote($table) . ' ADD COLUMN ' . $this->quote($column) . ' ' . ($type ?? 'TEXT');
    }

    public function createMigrationsTable(): string
    {
        return 'CREATE TABLE IF NOT EXISTS preflow_migrations (
            migration TEXT PRIMARY KEY,
            ran_at TEXT NOT NULL
        )';
    }

    public function canIndex(string $kind): bool
    {
        return true;
    }
}
