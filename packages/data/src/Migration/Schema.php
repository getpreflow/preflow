<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\Grammar\SchemaGrammar;

final class Schema
{
    private readonly SchemaGrammar $grammar;

    public function __construct(
        private readonly \PDO $pdo,
    ) {
        $this->grammar = Grammars::for($pdo);
    }

    public function create(string $table, callable $callback): void
    {
        $builder = new Table($table);
        $callback($builder);

        // Validate before touching the database, so a refused index never leaves a half-made table.
        $kinds = array_column($builder->columns(), 'kind', 'name');
        foreach ($builder->getIndexes() as $column) {
            if (!$this->grammar->canIndex($kinds[$column])) {
                throw new \LogicException(
                    "Cannot index TEXT column \"{$table}.{$column}\" on MySQL: use string() for indexed columns."
                );
            }
        }

        // Built first too: the grammar may refuse a name (MySQL's 64-character limit).
        $create = $this->grammar->createTable($builder->getName(), $builder->columns());
        $indexes = array_map(fn (string $column): string => $this->grammar->createIndex($table, $column), $builder->getIndexes());

        $this->pdo->exec($create);
        foreach ($indexes as $sql) {
            $this->pdo->exec($sql);
        }
    }

    public function drop(string $table): void
    {
        $this->pdo->exec($this->grammar->dropTable($table));
    }

    public function hasTable(string $table): bool
    {
        return $this->grammar->hasTable($this->pdo, $table);
    }

    /**
     * @return list<string> Column names in table order
     */
    public function columns(string $table): array
    {
        return $this->grammar->columns($this->pdo, $table);
    }

    /** @return array<string, string> the lower-case SQL type of each column, in table order */
    public function columnTypes(string $table): array
    {
        return $this->grammar->columnTypes($this->pdo, $table);
    }

    /** @return list<string> the names of the table's indexes */
    public function indexes(string $table): array
    {
        return $this->grammar->indexes($this->pdo, $table);
    }

    /** Adds a nullable column to an existing table; a null type is free text (TEXT / LONGTEXT). */
    public function addColumn(string $table, string $column, ?string $type = null): void
    {
        $this->pdo->exec($this->grammar->addColumn($table, $column, $type));
    }
}
