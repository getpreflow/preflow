<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\FieldKinds;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\Grammar\SchemaGrammar;
use Preflow\Data\Migration\Schema;
use Preflow\Data\TypeDefinition;

/**
 * The table of one SQL-stored type: created when missing, missing columns added. The id is the
 * primary key; every other column is nullable and typed by its field's column kind (FieldKinds),
 * or free text when the table is untyped. A declared index (a field's, or a composite one) is
 * made whenever it is missing, on new and existing columns alike; a unique index too, but only
 * over columns of their expected type that hold no value twice. An existing column is never
 * altered, so a column whose type differs from its kind (or a unique field without its index)
 * is reported as a warning naming the column to convert (data README, "Converting record
 * tables"). Nothing is ever dropped; JSON-stored definitions are left alone.
 *
 * @phpstan-import-type Entry from RecordTables
 */
final class RecordTableSync
{
    private const string CONVERT = 'convert the table (see the data README)';

    public function __construct(private readonly DataManager $dm) {}

    /**
     * @param list<Entry> $entries
     * @return list<string> the changes made and the warnings, one line each
     */
    public function syncAll(array $entries): array
    {
        $lines = [];
        foreach ($entries as $entry) {
            array_push($lines, ...$this->sync($entry['def'], $entry['extra'], $entry['typed'], $entry['unique']));
        }

        return $lines;
    }

    /**
     * @param list<string> $extraColumns columns besides the definition's fields (free text)
     * @param bool $typed false: every column free text and no indexes (history tables)
     * @param bool $unique false: no unique indexes (tables holding copies of records)
     * @return list<string> the changes made and the warnings, one line each
     */
    public function sync(TypeDefinition $def, array $extraColumns = [], bool $typed = true, bool $unique = true): array
    {
        $driver = $this->dm->driver($def->storage);
        if (!$driver instanceof PdoDriver) {
            return [];
        }

        $pdo = $driver->pdo();
        $schema = new Schema($pdo);
        $grammar = Grammars::for($pdo);
        $specs = $this->specs($def, $extraColumns, $typed);

        $lines = [];
        $drifted = [];
        $types = [];
        if (!$schema->hasTable($def->table)) {
            $pdo->exec($grammar->createRecordTable($def->table, $def->idField, array_values($specs)));
            $lines[] = "created table {$def->table}";
        } else {
            $types = $schema->columnTypes($def->table);
            foreach ($specs as $name => $spec) {
                $expected = $grammar->recordColumnType($spec->kind, $spec->length);
                if (!isset($types[$name])) {
                    $schema->addColumn($def->table, $name, $expected);
                    $lines[] = "added column {$def->table}.{$name}";
                } elseif ($types[$name] !== self::reported($expected)) {
                    $lines[] = "warning: {$def->table}.{$name} is {$types[$name]}, expected {$spec->kind->value}; " . self::CONVERT;
                    $drifted[] = $name;
                }
            }
        }
        if (!$typed) {
            return $lines;
        }

        $existing = $grammar->indexColumns($pdo, $def->table);
        $uniques = $grammar->uniqueIndexes($pdo, $def->table);
        foreach (self::indexes($grammar, $def, $specs, $unique) as $index) {
            $over = implode(', ', $index->columns);
            if ($index->freeText !== null) {
                $lines[] = "warning: {$def->table}: no index over {$over} ({$index->freeText} is free text); see the data README";
                continue;
            }
            if ($index->tooLongFor($grammar)) {
                $lines[] = "warning: {$def->table}: no index over {$over} (a key of up to {$index->bytes} bytes; MySQL allows "
                    . RecordIndex::MYSQL_MAX_KEY_BYTES . '); give its text fields a shorter "length"';
                continue;
            }
            if (self::found($index, $existing, $uniques)) {
                continue;
            }
            if (!$index->unique) {
                // An existing column may be TEXT (made before it was indexed): the grammar indexes a prefix where it must.
                try {
                    $pdo->exec($grammar->createRecordIndex($index->name, $def->table, $index->columns, false, $types, self::lengths($specs)));
                } catch (\PDOException $e) {
                    $lines[] = "warning: {$def->table}: no index over {$over} (the server refused it: {$e->getMessage()}); see the data README";
                    continue;
                }
                $lines[] = "added index {$index->name}";
                $existing[$index->name] = $index->columns;
                continue;
            }
            $field = $index->columns[0];
            // Only over columns of the expected type, and only when no value is there twice.
            if (array_intersect($index->columns, $drifted) !== []) {
                $lines[] = "warning: {$def->table}.{$field} has no unique index; " . self::CONVERT;
                continue;
            }
            $duplicates = self::duplicates($pdo, $grammar, $def->table, $index->columns);
            if ($duplicates > 0) {
                $lines[] = "warning: {$def->table}.{$field} has no unique index ({$duplicates} " . ($duplicates === 1 ? 'value is' : 'values are')
                    . ' there more than once); remove the duplicates (tools/convert-typed-columns.php --check lists them), the next migrate adds it';
                continue;
            }
            try {
                $pdo->exec($grammar->createRecordIndex($index->name, $def->table, $index->columns, true));
            } catch (\PDOException) {
                // A save wrote a duplicate since the count: the next migrate counts again.
                $lines[] = "warning: {$def->table}.{$field} has no unique index; " . self::CONVERT;
                continue;
            }
            $lines[] = "added unique index {$index->name}";
            $existing[$index->name] = $index->columns;
            $uniques[] = $index->name;
        }

        return $lines;
    }

    /**
     * The columns besides the id, each with its kind: the field's (a scope field of a unique
     * field and a field of a composite index count as indexed), free text for extra columns and
     * in untyped tables. Public: the conversion of existing tables gives them the same columns.
     *
     * @param list<string> $extraColumns
     * @return array<string, ColumnSpec>
     */
    public function specs(TypeDefinition $def, array $extraColumns, bool $typed): array
    {
        $indexed = $def->indexedByOthers();
        $specs = [];
        foreach ($def->fields as $name => $field) {
            if (!$typed) {
                $specs[$name] = new ColumnSpec($name);
                continue;
            }
            if (in_array($name, $indexed, true) && !$field->index) {
                $field = $field->withIndex();
            }
            $specs[$name] = new ColumnSpec($name, $this->dm->fieldKinds()->columnKindOf($field), $field->length);
        }
        foreach ($extraColumns as $name) {
            $specs[$name] ??= new ColumnSpec($name);
        }
        unset($specs[$def->idField]);

        return $specs;
    }

    /**
     * The indexes a typed sync gives a table: unique over each unique field and its scope (unless
     * $unique is false; one over a translatable or free-text column is checked on save instead and
     * not listed), then plain over each indexed field and each composite index (one over a
     * free-text column is listed with that column as freeText: sync warns about it). Each carries
     * the most bytes its key takes on MySQL, from the columns' kinds: sync warns about one past the
     * limit there (RecordIndex::tooLongFor()). Public: the conversion of existing tables makes the
     * same ones.
     *
     * @param array<string, ColumnSpec> $specs specs()
     * @return list<RecordIndex>
     */
    public static function indexes(SchemaGrammar $grammar, TypeDefinition $def, array $specs, bool $unique): array
    {
        $indexes = [];
        if ($unique) {
            foreach ($def->uniqueFields() as $field => $scope) {
                $columns = [$field, ...$scope];
                if (self::freeText($specs, $columns) === null) {
                    $indexes[] = new RecordIndex($grammar->indexName('uniq', $def->table, $field), $columns, true, null, self::bytes($specs, $columns));
                }
            }
        }
        foreach ([...array_map(static fn (string $field): array => [$field], $def->indexedFields()), ...$def->indexes] as $columns) {
            $freeText = self::freeText($specs, $columns);
            $indexes[] = new RecordIndex(
                $grammar->indexName('idx', $def->table, implode('_', $columns)),
                $columns,
                false,
                $freeText,
                $freeText === null ? self::bytes($specs, $columns) : 0,
            );
        }

        return $indexes;
    }

    /**
     * Whether the table has the index already: by its name, or an index (one made by hand, under
     * another name) over the same columns in the same order, for a unique one only a unique one.
     * Public: the conversion of existing tables skips the same ones.
     *
     * @param array<string, list<string>> $existing index name => its columns (SchemaGrammar::indexColumns())
     * @param list<string> $uniques the names of the unique ones (SchemaGrammar::uniqueIndexes())
     */
    public static function found(RecordIndex $index, array $existing, array $uniques): bool
    {
        if (isset($existing[$index->name])) {
            return true;
        }
        foreach ($existing as $name => $columns) {
            if ($columns === $index->columns && (!$index->unique || in_array($name, $uniques, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many values (over all the columns, none of them empty) more than one row holds.
     *
     * @param list<string> $columns
     */
    private static function duplicates(\PDO $pdo, SchemaGrammar $grammar, string $table, array $columns): int
    {
        $quoted = array_map($grammar->quote(...), $columns);
        $present = implode(' AND ', array_map(static fn (string $c): string => "{$c} IS NOT NULL", $quoted));
        $sql = 'SELECT COUNT(*) FROM (SELECT 1 FROM ' . $grammar->quote($table) . " WHERE {$present} GROUP BY "
            . implode(', ', $quoted) . ' HAVING COUNT(*) > 1) AS held';

        return (int) $pdo->query($sql)->fetchColumn();
    }

    /**
     * The most bytes a key over the columns takes on MySQL (utf8mb4): four a character of a short
     * text, eight a number, date or yes/no.
     *
     * @param array<string, ColumnSpec> $specs
     * @param list<string> $columns none of them free text
     */
    private static function bytes(array $specs, array $columns): int
    {
        $bytes = 0;
        foreach ($columns as $column) {
            $spec = $specs[$column];
            $bytes += $spec->kind === ColumnKind::Short ? ($spec->length ?? FieldKinds::DEFAULT_LENGTH) * 4 : 8;
        }

        return $bytes;
    }

    /**
     * The declared lengths of the short-text columns that have one, for a prefix index over a TEXT column.
     *
     * @param array<string, ColumnSpec> $specs
     * @return array<string, int>
     */
    private static function lengths(array $specs): array
    {
        $lengths = [];
        foreach ($specs as $name => $spec) {
            if ($spec->kind === ColumnKind::Short && $spec->length !== null) {
                $lengths[$name] = $spec->length;
            }
        }

        return $lengths;
    }

    /**
     * The first of the columns that can't carry an index (free text, or not a column), or null.
     *
     * @param array<string, ColumnSpec> $specs
     * @param list<string> $columns
     */
    private static function freeText(array $specs, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (!isset($specs[$column]) || $specs[$column]->kind === ColumnKind::Text) {
                return $column;
            }
        }

        return null;
    }

    /** A type as the server reports it: lower case, without its collation (MySQL's COLUMN_TYPE has none). */
    private static function reported(string $type): string
    {
        return strtolower(trim((string) preg_replace('/\s+COLLATE\s+\S+/i', '', $type)));
    }
}
