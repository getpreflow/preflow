<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

use Preflow\Data\FieldKinds;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;

/**
 * MariaDB 10.6+ / MySQL 8. InnoDB utf8mb4 limits index keys to 3072 bytes (767 on old row formats),
 * so primary keys and indexed columns stay VARCHAR; TEXT/LONGTEXT cannot be indexed without a prefix.
 * Ids and keys are utf8mb4_bin so they compare exactly, as on SQLite ('A', 'a' and 'ä' are three
 * rows, not one upsert target); value columns keep the case- and accent-insensitive table default.
 */
final class MysqlGrammar implements SchemaGrammar
{
    use RecordIndexNames;

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const EXACT = ' COLLATE utf8mb4_bin';

    /**
     * MySQL caps table, column and index names at 64 characters; idx_{table}_{column} and
     * Folio's {table}__published / __revisions can cross it, so fail naming the identifier.
     */
    public function quote(string $identifier): string
    {
        $length = mb_strlen($identifier);
        if ($length > 64) {
            throw new \LogicException("Identifier \"{$identifier}\" is {$length} characters; MySQL allows at most 64.");
        }

        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function columnType(string $kind, ?int $length = null): string
    {
        return match ($kind) {
            'uuid' => 'VARCHAR(64)' . self::EXACT,
            'string' => 'VARCHAR(' . ($length ?? 255) . ')',
            'text' => 'TEXT',
            'json' => 'LONGTEXT',
            'integer' => 'BIGINT',
            'boolean' => 'TINYINT(1)',
            'timestamp' => 'VARCHAR(32)',
            default => throw new \InvalidArgumentException("Unknown column kind \"{$kind}\"."),
        };
    }

    public function createTable(string $table, array $columns): string
    {
        $parts = [];

        foreach ($columns as $col) {
            $def = $this->quote($col['name']) . ' ' . $this->columnType($col['kind'], $col['length']);

            if ($col['primary'] && $col['kind'] === 'string') {
                $def .= self::EXACT;
            }

            if ($col['primary']) {
                $def .= ' PRIMARY KEY';
            } elseif (!$col['nullable']) {
                $def .= ' NOT NULL';
            }

            $parts[] = $def;
        }

        return 'CREATE TABLE ' . $this->quote($table) . ' (' . implode(', ', $parts) . ') ' . self::TABLE_OPTIONS;
    }

    public function createRecordTable(string $table, string $idField, array $columns): string
    {
        $defs = array_map(fn (ColumnSpec $c): string => $this->quote($c->name) . ' ' . $this->recordColumnType($c->kind, $c->length), $columns);

        return 'CREATE TABLE ' . $this->quote($table) . ' (' . $this->quote($idField) . ' VARCHAR(191)' . self::EXACT . ' PRIMARY KEY, '
            . implode(', ', $defs) . ') ' . self::TABLE_OPTIONS;
    }

    /** Short columns are unique or indexed: they compare exactly, as ids do. Dates stay ISO text. */
    public function recordColumnType(ColumnKind $kind, ?int $length = null): string
    {
        return match ($kind) {
            ColumnKind::Text => 'LONGTEXT',
            ColumnKind::Short => 'VARCHAR(' . ($length ?? FieldKinds::DEFAULT_LENGTH) . ')' . self::EXACT,
            ColumnKind::Number => 'DOUBLE',
            ColumnKind::Boolean => 'TINYINT(1)',
            ColumnKind::Date => 'VARCHAR(32)',
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
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    public function columns(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$table]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function columnTypes(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$table]);
        $types = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$name, $type]) {
            $types[(string) $name] = strtolower((string) $type);
        }

        return $types;
    }

    public function indexes(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function addColumn(string $table, string $column, ?string $type = null): string
    {
        return 'ALTER TABLE ' . $this->quote($table) . ' ADD COLUMN ' . $this->quote($column) . ' ' . ($type ?? 'LONGTEXT');
    }

    public function createMigrationsTable(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `preflow_migrations` (`migration` VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, `ran_at` VARCHAR(32) NOT NULL) '
            . self::TABLE_OPTIONS;
    }

    public function canIndex(string $kind): bool
    {
        return $kind !== 'text' && $kind !== 'json';
    }
}
