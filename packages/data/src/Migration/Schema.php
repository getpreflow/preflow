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
        $definitions = $builder->getIndexDefinitions();
        foreach ($definitions as $definition) {
            foreach ($definition['columns'] as $column) {
                if (!isset($kinds[$column])) {
                    throw new \LogicException("Cannot index unknown column \"{$table}.{$column}\".");
                }
                if (!$this->grammar->canIndex($kinds[$column])) {
                    throw new \LogicException(
                        "Cannot index TEXT column \"{$table}.{$column}\" on MySQL: use string() for indexed columns."
                    );
                }
            }
        }

        // Built first too: the grammar may refuse a name (MySQL's 64-character limit).
        $create = $this->grammar->createTable($builder->getName(), $builder->columns());
        $indexes = [];
        foreach ($definitions as $definition) {
            $columns = $definition['columns'];
            if (count($columns) === 1 && !$definition['unique']) {
                $indexes[] = $this->grammar->createIndex($table, $columns[0]);
                continue;
            }
            $name = $this->grammar->indexName($definition['unique'] ? 'uniq' : 'idx', $table, implode('_', $columns));
            $indexes[] = $this->grammar->createRecordIndex($name, $table, $columns, $definition['unique']);
        }

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

    /**
     * Adds the columns the callback declares (as in create(): string(), boolean(), …, with index())
     * to an existing table, typed by the storage's grammar; every added column is nullable (rows that
     * exist have no value yet: fillNulls() backfills one). Refuses (LogicException, nothing changed)
     * a column the table already has, and a primary key.
     */
    public function addColumns(string $table, callable $callback): void
    {
        $builder = new Table($table);
        $callback($builder);
        $existing = $this->columns($table);
        foreach ($builder->columns() as $column) {
            if (in_array($column['name'], $existing, true)) {
                throw new \LogicException("Cannot add \"{$table}.{$column['name']}\": the table already has a column \"{$column['name']}\".");
            }
            if ($column['primary']) {
                throw new \LogicException("Cannot add the primary key \"{$table}.{$column['name']}\" to an existing table.");
            }
        }
        $statements = [];
        foreach ($builder->columns() as $column) {
            $statements[] = $this->grammar->addColumn($table, $column['name'], $this->grammar->columnType($column['kind'], $column['length']));
        }
        $kinds = array_column($builder->columns(), 'kind', 'name');
        foreach ($builder->getIndexDefinitions() as $definition) {
            foreach ($definition['columns'] as $name) {
                if (isset($kinds[$name]) && !$this->grammar->canIndex($kinds[$name])) {
                    throw new \LogicException("Cannot index TEXT column \"{$table}.{$name}\" on MySQL: use string() for indexed columns.");
                }
            }
            $columns = $definition['columns'];
            $statements[] = count($columns) === 1 && !$definition['unique']
                ? $this->grammar->createIndex($table, $columns[0])
                : $this->grammar->createRecordIndex($this->grammar->indexName($definition['unique'] ? 'uniq' : 'idx', $table, implode('_', $columns)), $table, $columns, $definition['unique']);
        }
        foreach ($statements as $sql) {
            $this->pdo->exec($sql);
        }
    }

    /** Sets the column to $value on every row where it is NULL (a backfill after addColumns()); returns how many rows. */
    public function fillNulls(string $table, string $column, string|int|float|bool $value): int
    {
        $quoted = $this->grammar->quote($column);
        $stmt = $this->pdo->prepare('UPDATE ' . $this->grammar->quote($table) . ' SET ' . $quoted . ' = ? WHERE ' . $quoted . ' IS NULL');
        $stmt->execute([is_bool($value) ? (int) $value : $value]);

        return $stmt->rowCount();
    }

    /**
     * Renames a column, keeping its data, type and indexes (SQLite 3.25+, MySQL 8). Refuses
     * (LogicException, nothing changed) when the table has no column $from or already has one named $to.
     */
    public function renameColumn(string $table, string $from, string $to): void
    {
        $columns = $this->columns($table);
        if (!in_array($from, $columns, true)) {
            throw new \LogicException("Cannot rename \"{$table}.{$from}\": the table has no column \"{$from}\".");
        }
        if (in_array($to, $columns, true)) {
            throw new \LogicException("Cannot rename \"{$table}.{$from}\" to \"{$to}\": the table already has a column \"{$to}\".");
        }
        $this->pdo->exec($this->grammar->renameColumn($table, $from, $to));
    }

    /** Adds a nullable column to an existing table; a null type is free text (TEXT / LONGTEXT). */
    public function addColumn(string $table, string $column, ?string $type = null): void
    {
        $this->pdo->exec($this->grammar->addColumn($table, $column, $type));
    }
}
