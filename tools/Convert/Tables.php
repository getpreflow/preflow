<?php

declare(strict_types=1);

namespace Preflow\Tools\Convert;

use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\Grammar\SchemaGrammar;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;

/**
 * The typed record tables of one SQL storage (RecordTables entries; history tables left out),
 * each as a fresh typed sync would create it, and what each existing one still needs.
 */
final class Tables
{
    public readonly SchemaGrammar $grammar;
    public readonly Schema $schema;

    /** @param list<Table> $tables */
    private function __construct(
        public readonly \PDO $pdo,
        public readonly array $tables,
    ) {
        $this->grammar = Grammars::for($pdo);
        $this->schema = new Schema($pdo);
    }

    /** @param list<array{def: TypeDefinition, extra: list<string>, typed: bool, unique: bool}> $entries */
    public static function from(DataManager $dm, array $entries, string $storage): self
    {
        $driver = $dm->driver($storage);
        if (!$driver instanceof PdoDriver) {
            throw new \RuntimeException("Storage [{$storage}] is not a SQL storage.");
        }
        $grammar = Grammars::for($driver->pdo());
        $sync = new RecordTableSync($dm);
        $tables = [];
        foreach ($entries as $entry) {
            $def = $entry['def'];
            if (!$entry['typed'] || isset($tables[$def->table]) || !self::storedIn($dm, $def, $driver)) {
                continue;
            }
            $specs = $sync->specs($def, $entry['extra'], true);
            $tables[$def->table] = new Table(
                $def->table,
                $def->idField,
                $specs,
                self::emptyToNull($def, $specs),
                self::indexes($grammar, $def, $specs, $entry['unique']),
            );
        }

        return new self($driver->pdo(), array_values($tables));
    }

    /** @return list<Work> the existing tables that are not typed yet, in order */
    public function pending(): array
    {
        $work = [];
        foreach ($this->tables as $table) {
            $w = $this->work($table);
            if ($w !== null) {
                $work[] = $w;
            }
        }

        return $work;
    }

    /** What the table still needs; null when it is missing (sync creates it typed) or already typed. */
    public function work(Table $table): ?Work
    {
        if (!$this->schema->hasTable($table->name)) {
            return null;
        }
        $types = $this->schema->columnTypes($table->name);
        unset($types[$table->idField]);
        $text = self::reported($this->grammar->recordColumnType(ColumnKind::Text));
        $retype = [];
        $check = [];
        foreach ($table->specs as $name => $spec) {
            if (!isset($types[$name])) {
                continue; // a field added since: migrate adds it typed
            }
            $expected = self::reported($this->grammar->recordColumnType($spec->kind, $spec->length));
            if ($types[$name] !== $expected) {
                $retype[] = $name;
            }
            // A kind stored as plain text (dates and short text on SQLite) never shows it is typed.
            if ($spec->kind !== ColumnKind::Text && ($types[$name] !== $expected || $expected === $text)) {
                $check[$name] = $spec;
            }
        }
        $existing = $this->schema->indexes($table->name);
        $missing = array_values(array_filter(
            $table->indexes,
            static fn (Index $i): bool => !in_array($i->name, $existing, true) && array_diff($i->columns, array_keys($types)) === [],
        ));
        $empties = array_values(array_filter(
            $table->emptyToNull,
            fn (string $column): bool => isset($types[$column]) && $this->hasEmpty($table->name, $column),
        ));
        if ($retype === [] && $missing === [] && $empties === []) {
            return null;
        }

        return new Work($table, array_keys($types), $retype, $check, $missing, $empties);
    }

    public function isMysql(): bool
    {
        return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /** @return list<array<string, mixed>> every row, the id under the table's id field */
    public function rows(Table $table): array
    {
        return $this->pdo->query('SELECT * FROM ' . $this->grammar->quote($table->name))->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function hasEmpty(string $table, string $column): bool
    {
        $c = $this->grammar->quote($column);
        $empty = $this->isMysql() ? "CHAR_LENGTH({$c}) = 0" : "{$c} = ''";

        return $this->pdo->query('SELECT 1 FROM ' . $this->grammar->quote($table) . " WHERE {$empty} LIMIT 1")->fetchColumn() !== false;
    }

    private static function storedIn(DataManager $dm, TypeDefinition $def, PdoDriver $driver): bool
    {
        try {
            return $dm->driver($def->storage) === $driver;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * As DataManager::storageValues() writes them: '' is NULL in number, yes/no and date
     * columns and in a unique field's own (indexable) column.
     *
     * @param array<string, ColumnSpec> $specs
     * @return list<string>
     */
    private static function emptyToNull(TypeDefinition $def, array $specs): array
    {
        $columns = [];
        foreach ($def->fields as $name => $field) {
            $kind = $specs[$name]->kind ?? null;
            if ($kind === null) {
                continue;
            }
            if (in_array($kind, [ColumnKind::Number, ColumnKind::Boolean, ColumnKind::Date], true) || ($field->unique !== false && $kind !== ColumnKind::Text)) {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    /**
     * The indexes RecordTableSync gives the table: unique over each indexable unique field and its
     * scope (unless $unique is false), plain over each indexable indexed field.
     *
     * @param array<string, ColumnSpec> $specs
     * @return list<Index>
     */
    private static function indexes(SchemaGrammar $grammar, TypeDefinition $def, array $specs, bool $unique): array
    {
        $indexable = static function (array $columns) use ($specs): bool {
            foreach ($columns as $column) {
                if (!isset($specs[$column]) || $specs[$column]->kind === ColumnKind::Text) {
                    return false;
                }
            }

            return true;
        };
        $indexes = [];
        if ($unique) {
            foreach ($def->uniqueFields() as $field => $scope) {
                if ($indexable([$field, ...$scope])) {
                    $indexes[] = new Index($grammar->indexName('uniq', $def->table, $field), [$field, ...$scope], true);
                }
            }
        }
        foreach ($def->indexedFields() as $field) {
            if ($indexable([$field])) {
                $indexes[] = new Index($grammar->indexName('idx', $def->table, $field), [$field], false);
            }
        }

        return $indexes;
    }

    /** A type as the server reports it: lower case, without its collation (as RecordTableSync compares). */
    public static function reported(string $type): string
    {
        return strtolower(trim((string) preg_replace('/\s+COLLATE\s+\S+/i', '', $type)));
    }
}
