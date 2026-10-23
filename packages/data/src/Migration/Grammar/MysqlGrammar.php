<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

/**
 * MySQL 8 / MariaDB 10.6. InnoDB utf8mb4 limits index keys to 3072 bytes (767 on old row formats),
 * so primary keys and indexed columns stay VARCHAR; TEXT/LONGTEXT cannot be indexed without a prefix.
 */
final class MysqlGrammar implements SchemaGrammar
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function columnType(string $kind, ?int $length = null): string
    {
        return match ($kind) {
            'uuid' => 'VARCHAR(64)',
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
        $defs = array_map(fn (string $c): string => $this->quote($c) . ' LONGTEXT', $columns);

        return 'CREATE TABLE ' . $this->quote($table) . ' (' . $this->quote($idField) . ' VARCHAR(191) PRIMARY KEY, '
            . implode(', ', $defs) . ') ' . self::TABLE_OPTIONS;
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

    public function addColumn(string $table, string $column, ?string $type = null): string
    {
        return 'ALTER TABLE ' . $this->quote($table) . ' ADD COLUMN ' . $this->quote($column) . ' ' . ($type ?? 'LONGTEXT');
    }

    public function createMigrationsTable(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `preflow_migrations` (`migration` VARCHAR(191) PRIMARY KEY, `ran_at` VARCHAR(32) NOT NULL) '
            . self::TABLE_OPTIONS;
    }

    public function canIndex(string $kind): bool
    {
        return $kind !== 'text' && $kind !== 'json';
    }
}
