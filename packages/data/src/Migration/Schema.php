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
        $indexes = $this->indexStatements($table, $builder, array_column($builder->columns(), 'kind', 'name'));
        // Built first too: the grammar may refuse a name (MySQL's 64-character limit).
        $create = $this->grammar->createTable($builder->getName(), $builder->columns());

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
            if (self::find($existing, $column['name']) !== null) {
                throw new \LogicException("Cannot add \"{$table}.{$column['name']}\": the table already has a column \"{$column['name']}\".");
            }
            if ($column['primary']) {
                throw new \LogicException("Cannot add the primary key \"{$table}.{$column['name']}\" to an existing table.");
            }
        }
        // An index may name the added columns and those the table has (their kind unknown: not refused for it).
        $kinds = array_fill_keys($existing, null) + array_column($builder->columns(), 'kind', 'name');
        $indexes = $this->indexStatements($table, $builder, $kinds);
        $statements = [];
        foreach ($builder->columns() as $column) {
            $statements[] = $this->grammar->addColumn($table, $column['name'], $this->grammar->columnType($column['kind'], $column['length']));
        }
        foreach ([...$statements, ...$indexes] as $sql) {
            $this->pdo->exec($sql);
        }
    }

    /**
     * Copies $from into $to on every row where $to is NULL (merging an old column into its
     * replacement; a value already in $to wins); returns how many rows. Refuses (LogicException) a
     * column the table doesn't have.
     */
    public function copyColumn(string $table, string $from, string $to): int
    {
        $columns = $this->columns($table);
        $stmt = $this->pdo->prepare($this->grammar->copyColumn($table, $this->existing($table, $columns, $from), $this->existing($table, $columns, $to)));
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Drops a column and its data (SQLite 3.35+, MySQL). Refuses (LogicException, nothing changed) a
     * column the table doesn't have, a column that belongs to any index (SQLite would fail, MySQL would
     * silently narrow the index: drop the index first), and an SQLite older than 3.35.
     */
    public function dropColumn(string $table, string $column): void
    {
        $this->grammar->assertCanDropColumn($this->pdo);
        $own = $this->existing($table, $this->columns($table), $column);
        foreach ($this->grammar->indexColumns($this->pdo, $table) as $index => $indexed) {
            if (self::find($indexed, $own) !== null) {
                throw new \LogicException("Cannot drop \"{$table}.{$own}\": it is part of the index \"{$index}\". Drop the index first.");
            }
        }
        $this->pdo->exec($this->grammar->dropColumn($table, $own));
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
        $own = self::find($columns, $from);
        if ($own === null) {
            throw new \LogicException("Cannot rename \"{$table}.{$from}\": the table has no column \"{$from}\".");
        }
        if (self::find($columns, $to) !== null && strcasecmp($own, $to) !== 0) {
            throw new \LogicException("Cannot rename \"{$table}.{$from}\" to \"{$to}\": the table already has a column \"{$to}\".");
        }
        $this->pdo->exec($this->grammar->renameColumn($table, $own, $to));
    }

    /** Adds a nullable column to an existing table; a null type is free text (TEXT / LONGTEXT). */
    public function addColumn(string $table, string $column, ?string $type = null): void
    {
        $this->pdo->exec($this->grammar->addColumn($table, $column, $type));
    }

    /**
     * Checks a builder's indexes and returns their SQL, before anything runs: every indexed column
     * must be in $kinds (name => kind, or null for a column the table has), and MySQL can't index TEXT.
     *
     * @param array<string, ?string> $kinds
     * @return list<string>
     */
    private function indexStatements(string $table, Table $builder, array $kinds): array
    {
        $statements = [];
        foreach ($builder->getIndexDefinitions() as $definition) {
            foreach ($definition['columns'] as $column) {
                $known = self::find(array_keys($kinds), $column);
                if ($known === null) {
                    throw new \LogicException("Cannot index unknown column \"{$table}.{$column}\".");
                }
                if ($kinds[$known] !== null && !$this->grammar->canIndex($kinds[$known])) {
                    throw new \LogicException(
                        "Cannot index TEXT column \"{$table}.{$column}\" on MySQL: use string() for indexed columns."
                    );
                }
            }
            $columns = $definition['columns'];
            if (count($columns) === 1 && !$definition['unique']) {
                $statements[] = $this->grammar->createIndex($table, $columns[0]);
                continue;
            }
            $name = $this->grammar->indexName($definition['unique'] ? 'uniq' : 'idx', $table, implode('_', $columns));
            $statements[] = $this->grammar->createRecordIndex($name, $table, $columns, $definition['unique']);
        }

        return $statements;
    }

    /**
     * The table's own spelling of a column name, compared without case (as SQLite and MySQL do); null when absent.
     *
     * @param list<string> $columns
     */
    private static function find(array $columns, string $name): ?string
    {
        foreach ($columns as $column) {
            if (strcasecmp($column, $name) === 0) {
                return $column;
            }
        }

        return null;
    }

    /** @param list<string> $columns */
    private function existing(string $table, array $columns, string $name): string
    {
        return self::find($columns, $name) ?? throw new \LogicException("Cannot change \"{$table}.{$name}\": the table has no column \"{$name}\".");
    }
}
