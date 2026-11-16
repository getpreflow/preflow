<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use Preflow\Data\Migration\Grammar\SchemaGrammar;
use Preflow\Data\Migration\Grammar\SqliteGrammar;
use Preflow\Data\Migration\Grammar\TextCollations;
use Preflow\Data\Schema\ColumnKind;

/**
 * A grammar with TextCollations alone (as one for a database new to Preflow would be): new
 * columns carry the rule, existing ones are not converted (no ConvertsCollations). Delegates to
 * SQLite's grammar.
 */
final class RuleOnlyGrammar implements SchemaGrammar, TextCollations
{
    public function __construct(private SqliteGrammar $inner = new SqliteGrammar())
    {
    }

    public function on(\PDO $pdo): static
    {
        return new self($this->inner->on($pdo));
    }

    public function textCollation(): ?string { return $this->inner->textCollation(); }
    public function textColumnType(ColumnKind $kind, ?int $length = null): string { return $this->inner->textColumnType($kind, $length); }
    public function textType(string $kind, ?int $length = null): string { return $this->inner->textType($kind, $length); }
    public function exactType(string $kind, ?int $length = null): string { return $this->inner->exactType($kind, $length); }
    public function collate(string $expression, string $collation): string { return $this->inner->collate($expression, $collation); }

    public function quote(string $identifier): string { return $this->inner->quote($identifier); }
    public function columnType(string $kind, ?int $length = null): string { return $this->inner->columnType($kind, $length); }
    public function createTable(string $table, array $columns): string { return $this->inner->createTable($table, $columns); }
    public function createRecordTable(string $table, string $idField, array $columns): string { return $this->inner->createRecordTable($table, $idField, $columns); }
    public function recordColumnType(ColumnKind $kind, ?int $length = null): string { return $this->inner->recordColumnType($kind, $length); }
    public function indexName(string $prefix, string $table, string $field): string { return $this->inner->indexName($prefix, $table, $field); }
    public function createRecordIndex(string $name, string $table, array $columns, bool $unique, array $columnTypes = [], array $lengths = []): string { return $this->inner->createRecordIndex($name, $table, $columns, $unique, $columnTypes, $lengths); }
    public function createIndex(string $table, string $column): string { return $this->inner->createIndex($table, $column); }
    public function dropTable(string $table): string { return $this->inner->dropTable($table); }
    public function hasTable(\PDO $pdo, string $table): bool { return $this->inner->hasTable($pdo, $table); }
    public function columns(\PDO $pdo, string $table): array { return $this->inner->columns($pdo, $table); }
    public function columnTypes(\PDO $pdo, string $table): array { return $this->inner->columnTypes($pdo, $table); }
    public function indexes(\PDO $pdo, string $table): array { return $this->inner->indexes($pdo, $table); }
    public function indexColumns(\PDO $pdo, string $table): array { return $this->inner->indexColumns($pdo, $table); }
    public function uniqueIndexes(\PDO $pdo, string $table): array { return $this->inner->uniqueIndexes($pdo, $table); }
    public function dropIndex(string $table, string $index): string { return $this->inner->dropIndex($table, $index); }
    public function assertCanDropColumn(\PDO $pdo): void { $this->inner->assertCanDropColumn($pdo); }
    public function addColumn(string $table, string $column, ?string $type = null): string { return $this->inner->addColumn($table, $column, $type); }
    public function renameColumn(string $table, string $from, string $to): string { return $this->inner->renameColumn($table, $from, $to); }
    public function copyColumn(string $table, string $from, string $to): string { return $this->inner->copyColumn($table, $from, $to); }
    public function dropColumn(string $table, string $column): string { return $this->inner->dropColumn($table, $column); }
    public function convertColumn(\PDO $pdo, string $table, string $column, string $type, ?bool $nullable): void { $this->inner->convertColumn($pdo, $table, $column, $type, $nullable); }
    public function createMigrationsTable(): string { return $this->inner->createMigrationsTable(); }
    public function canIndex(string $kind): bool { return $this->inner->canIndex($kind); }
}
