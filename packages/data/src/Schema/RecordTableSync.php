<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
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

        $existing = $schema->indexes($def->table);
        if ($unique) {
            foreach ($def->uniqueFields() as $field => $scope) {
                $columns = [$field, ...$scope];
                if (!self::indexable($specs, $columns)) {
                    continue; // translatable or free text: checked on save, not by the database
                }
                $name = $grammar->indexName('uniq', $def->table, $field);
                if (in_array($name, $existing, true)) {
                    continue;
                }
                // Only over columns of the expected type, and only when no value is there twice.
                $duplicates = array_intersect($columns, $drifted) === [] ? self::duplicates($pdo, $grammar, $def->table, $columns) : null;
                if ($duplicates === 0) {
                    $pdo->exec($grammar->createRecordIndex($name, $def->table, $columns, true));
                    $lines[] = "added unique index {$name}";
                    $existing[] = $name;
                } else {
                    $held = $duplicates === null ? '' : " ({$duplicates} " . ($duplicates === 1 ? 'value is' : 'values are') . ' there more than once)';
                    $lines[] = "warning: {$def->table}.{$field} has no unique index{$held}; " . self::CONVERT;
                }
            }
        }
        foreach ([...array_map(static fn (string $field): array => [$field], $def->indexedFields()), ...$def->indexes] as $columns) {
            $name = $grammar->indexName('idx', $def->table, implode('_', $columns));
            if (self::indexable($specs, $columns) && !in_array($name, $existing, true)) {
                // An existing column may be TEXT (made before it was indexed): the grammar indexes a prefix where it must.
                $pdo->exec($grammar->createRecordIndex($name, $def->table, $columns, false, $types));
                $lines[] = "added index {$name}";
                $existing[] = $name;
            }
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
     * @param array<string, ColumnSpec> $specs
     * @param list<string> $columns
     */
    private static function indexable(array $specs, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!isset($specs[$column]) || $specs[$column]->kind === ColumnKind::Text) {
                return false;
            }
        }

        return true;
    }

    /** A type as the server reports it: lower case, without its collation (MySQL's COLUMN_TYPE has none). */
    private static function reported(string $type): string
    {
        return strtolower(trim((string) preg_replace('/\s+COLLATE\s+\S+/i', '', $type)));
    }
}
