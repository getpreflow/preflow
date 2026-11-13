<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;

/**
 * Everything in Schema/Migrator that differs per SQL server: quoting, column types,
 * DDL text and introspection. Column kinds: uuid|string|text|json|integer|boolean|timestamp.
 */
interface SchemaGrammar
{
    public function quote(string $identifier): string;

    public function columnType(string $kind, ?int $length = null): string;

    /**
     * @param list<array{name: string, kind: string, length: ?int, nullable: bool, primary: bool}> $columns
     */
    public function createTable(string $table, array $columns): string;

    /**
     * Record table: text id as primary key, every other column nullable and of its kind.
     *
     * @param list<ColumnSpec> $columns
     */
    public function createRecordTable(string $table, string $idField, array $columns): string;

    /** The SQL type of a record table column of this kind ($length: a Short column's, null for the default). */
    public function recordColumnType(ColumnKind $kind, ?int $length = null): string;

    /** A record table's index name: "{prefix}_{table}_{field}", shortened with a hash past 64 characters. */
    public function indexName(string $prefix, string $table, string $field): string;

    /**
     * @param list<string> $columns
     * @param array<string, string> $columnTypes the columns' types as columnTypes() reports them, for columns
     *        the table already has: a server that can't index a TEXT column whole indexes a prefix
     */
    public function createRecordIndex(string $name, string $table, array $columns, bool $unique, array $columnTypes = []): string;

    public function createIndex(string $table, string $column): string;

    public function dropTable(string $table): string;

    public function hasTable(\PDO $pdo, string $table): bool;

    /** @return list<string> Column names in table order */
    public function columns(\PDO $pdo, string $table): array;

    /** @return array<string, string> the lower-case SQL type of each column, in table order */
    public function columnTypes(\PDO $pdo, string $table): array;

    /** @return list<string> the names of the table's indexes */
    public function indexes(\PDO $pdo, string $table): array;

    /** @return array<string, list<string>> each index's columns, by index name */
    public function indexColumns(\PDO $pdo, string $table): array;

    /** @return list<string> the names of the table's unique indexes (the primary key's too). Since 0.72. */
    public function uniqueIndexes(\PDO $pdo, string $table): array;

    /** The SQL that drops the index $index of $table. */
    public function dropIndex(string $table, string $index): string;

    /** Throws LogicException when this server cannot drop a column (before any SQL runs); a no-op when it can. */
    public function assertCanDropColumn(\PDO $pdo): void;

    /** Adds a nullable column; a null type means the grammar's free-text type. */
    public function addColumn(string $table, string $column, ?string $type = null): string;

    /** ALTER TABLE … RENAME COLUMN … TO … (SQLite 3.25+, MySQL 8, PostgreSQL); the data stays. Since 0.65. */
    public function renameColumn(string $table, string $from, string $to): string;

    /** UPDATE … SET <to> = <from> WHERE <to> IS NULL (merging an old column into its replacement). Since 0.65. */
    public function copyColumn(string $table, string $from, string $to): string;

    /** ALTER TABLE … DROP COLUMN … (SQLite 3.35+, MySQL). Since 0.65. */
    public function dropColumn(string $table, string $column): string;

    public function createMigrationsTable(): string;

    /** Whether a column of this kind can carry an index. */
    public function canIndex(string $kind): bool;
}
