<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\Schema;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * The table of one SQL-stored type: created when missing, missing columns added. The id is the
 * primary key; every other column is nullable and typed by its field's column kind (FieldKinds),
 * or free text when the table is untyped. Unique and plain indexes come only with the columns
 * this sync creates: an existing column is never altered and never gets an index, so a column
 * whose type differs from its kind (or a unique field without its index) is reported as a
 * warning for the conversion command. Nothing is ever dropped; JSON-stored definitions are
 * left alone.
 *
 * @phpstan-import-type Entry from RecordTables
 */
final class RecordTableSync
{
    private const string CONVERT = 'run preflow data:convert';

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
        $created = [];
        $drifted = [];
        if (!$schema->hasTable($def->table)) {
            $pdo->exec($grammar->createRecordTable($def->table, $def->idField, array_values($specs)));
            $lines[] = "created table {$def->table}";
            $created = array_keys($specs);
        } else {
            $types = $schema->columnTypes($def->table);
            foreach ($specs as $name => $spec) {
                $expected = $grammar->recordColumnType($spec->kind, $spec->length);
                if (!isset($types[$name])) {
                    $schema->addColumn($def->table, $name, $expected);
                    $lines[] = "added column {$def->table}.{$name}";
                    $created[] = $name;
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
                // Only with the field's new column, and only over scope columns of the expected type.
                if (in_array($field, $created, true) && array_intersect($scope, $drifted) === []) {
                    $pdo->exec($grammar->createRecordIndex($name, $def->table, $columns, true));
                    $lines[] = "added unique index {$name}";
                } else {
                    $lines[] = "warning: {$def->table}.{$field} has no unique index; " . self::CONVERT;
                }
            }
        }
        foreach ($def->indexedFields() as $field) {
            $name = $grammar->indexName('idx', $def->table, $field);
            if (in_array($field, $created, true) && self::indexable($specs, [$field]) && !in_array($name, $existing, true)) {
                $pdo->exec($grammar->createRecordIndex($name, $def->table, [$field], false));
                $lines[] = "added index {$name}";
            }
        }

        return $lines;
    }

    /**
     * The columns besides the id, each with its kind: the field's (a scope field of a unique
     * field counts as indexed), free text for extra columns and in untyped tables.
     *
     * @param list<string> $extraColumns
     * @return array<string, ColumnSpec>
     */
    private function specs(TypeDefinition $def, array $extraColumns, bool $typed): array
    {
        $scopes = [];
        foreach ($def->uniqueFields() as $scope) {
            array_push($scopes, ...$scope);
        }
        $specs = [];
        foreach ($def->fields as $name => $field) {
            if (!$typed) {
                $specs[$name] = new ColumnSpec($name);
                continue;
            }
            if (in_array($name, $scopes, true) && !$field->index) {
                $field = self::asIndexed($field);
            }
            $specs[$name] = new ColumnSpec($name, $this->dm->fieldKinds()->columnKindOf($field), $field->length);
        }
        foreach ($extraColumns as $name) {
            $specs[$name] ??= new ColumnSpec($name);
        }
        unset($specs[$def->idField]);

        return $specs;
    }

    private static function asIndexed(TypeFieldDefinition $f): TypeFieldDefinition
    {
        return new TypeFieldDefinition(
            $f->name, $f->type, $f->searchable, $f->transform, $f->validate, $f->label, $f->help, $f->config,
            $f->translatable, $f->labels, $f->helps, $f->unique, true, $f->length,
        );
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
