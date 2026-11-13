<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;

/** The DDL text here is pinned by SqliteDdlCharacterisationTest: existing sites must not see changes. */
final class SqliteGrammar implements SchemaGrammar
{
    use RecordIndexNames;

    /** @param (\Closure(\PDO): string)|null $version reports the server's SQLite version (default: sqlite_version()); injectable for tests */
    public function __construct(private readonly ?\Closure $version = null)
    {
    }

    public function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
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

    public function createRecordIndex(string $name, string $table, array $columns, bool $unique, array $columnTypes = [], array $lengths = []): string
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

    public function uniqueIndexes(\PDO $pdo, string $table): array
    {
        $rows = $pdo->query('PRAGMA index_list(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $row): string => (string) $row['name'], array_filter($rows, static fn (array $row): bool => (int) $row['unique'] === 1)));
    }

    public function indexColumns(\PDO $pdo, string $table): array
    {
        $result = [];
        foreach ($this->indexes($pdo, $table) as $name) {
            $rows = $pdo->query('PRAGMA index_info(' . $this->quote($name) . ')')->fetchAll(\PDO::FETCH_ASSOC);
            $result[$name] = array_map(static fn (array $row): string => (string) $row['name'], $rows);
        }

        return $result;
    }

    public function dropIndex(string $table, string $index): string
    {
        if (str_starts_with(strtolower($index), 'sqlite_autoindex_')) {
            throw new \LogicException("Cannot drop the index \"{$index}\": SQLite creates it for a PRIMARY KEY or UNIQUE constraint of the table and cannot drop it.");
        }

        return 'DROP INDEX ' . $this->quote($index);
    }

    public function assertCanDropColumn(\PDO $pdo): void
    {
        $version = $this->version !== null ? ($this->version)($pdo) : (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
        if (version_compare($version, '3.35.0', '<')) {
            throw new \LogicException("dropColumn needs SQLite 3.35 or newer; this server has {$version}.");
        }
    }

    public function addColumn(string $table, string $column, ?string $type = null): string
    {
        return 'ALTER TABLE ' . $this->quote($table) . ' ADD COLUMN ' . $this->quote($column) . ' ' . ($type ?? 'TEXT');
    }

    public function renameColumn(string $table, string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->quote($table) . ' RENAME COLUMN ' . $this->quote($from) . ' TO ' . $this->quote($to);
    }

    public function copyColumn(string $table, string $from, string $to): string
    {
        return 'UPDATE ' . $this->quote($table) . ' SET ' . $this->quote($to) . ' = ' . $this->quote($from) . ' WHERE ' . $this->quote($to) . ' IS NULL';
    }

    public function dropColumn(string $table, string $column): string
    {
        return 'ALTER TABLE ' . $this->quote($table) . ' DROP COLUMN ' . $this->quote($column);
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
