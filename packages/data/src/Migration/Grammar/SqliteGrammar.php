<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;

/** The DDL text here is pinned by SqliteDdlCharacterisationTest: existing sites must not see changes. */
final class SqliteGrammar implements SchemaGrammar
{
    use RecordIndexNames;

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
        $defs = array_map(fn (ColumnSpec $c): string => $this->quote($c->name) . ' ' . $this->recordColumnType($c->kind, $c->length), $columns);

        return 'CREATE TABLE ' . $this->quote($table) . ' (' . $this->quote($idField) . ' TEXT PRIMARY KEY, '
            . implode(', ', $defs) . ')';
    }

    /**
     * NUMERIC, not REAL: '12' stays the integer 12 (REAL would read back 12.0), '12.5' becomes 12.5,
     * and numbers sort numerically. SQLite has no bounded strings: Short is TEXT.
     */
    public function recordColumnType(ColumnKind $kind, ?int $length = null): string
    {
        return match ($kind) {
            ColumnKind::Number => 'NUMERIC',
            ColumnKind::Boolean => 'INTEGER',
            ColumnKind::Text, ColumnKind::Short, ColumnKind::Date => 'TEXT',
        };
    }

    public function createRecordIndex(string $name, string $table, array $columns, bool $unique): string
    {
        return 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $this->quote($name) . ' ON ' . $this->quote($table)
            . ' (' . implode(', ', array_map($this->quote(...), $columns)) . ')';
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

    public function columnTypes(\PDO $pdo, string $table): array
    {
        $types = [];
        foreach ($pdo->query('PRAGMA table_info(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $types[(string) $row['name']] = strtolower((string) $row['type']);
        }

        return $types;
    }

    public function indexes(\PDO $pdo, string $table): array
    {
        $rows = $pdo->query('PRAGMA index_list(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC);

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
