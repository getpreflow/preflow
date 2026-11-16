<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\Grammar\TextCollations;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;

/**
 * StorageDriverContract::createType() for the SQL drivers: the record table the schema grammar
 * creates for a type (as RecordTableSync does), or, for a number id, one with an auto-increment
 * key.
 */
trait ContractTables
{
    /** @param array<string, 'string'|'text'|'number'|'boolean'|'json'> $fields the id field first ('text': a text field, the rest exact) */
    private static function createContractTable(PdoDriver $driver, string $type, array $fields, string $autoIdColumn): void
    {
        $idField = array_key_first($fields);
        $specs = [];
        foreach (array_slice($fields, 1, null, true) as $name => $kind) {
            $specs[] = new ColumnSpec($name, match ($kind) {
                'number' => ColumnKind::Number,
                'boolean' => ColumnKind::Boolean,
                'string', 'text', 'json' => ColumnKind::Text,
            }, text: $kind === 'text');
        }
        $grammar = Grammars::for($driver->pdo());

        if ($fields[$idField] !== 'number') {
            $driver->pdo()->exec($grammar->createRecordTable($type, $idField, $specs));

            return;
        }
        $quote = $driver->dialect()->quoteIdentifier(...);
        $columns = array_map(static fn (ColumnSpec $c): string => $quote($c->name) . ' ' . ($c->text && $grammar instanceof TextCollations ? $grammar->textColumnType($c->kind) : $grammar->recordColumnType($c->kind)), $specs);
        $driver->pdo()->exec('CREATE TABLE ' . $quote($type) . ' (' . $quote($idField) . " {$autoIdColumn}, " . implode(', ', $columns) . ')');
    }

    /** A unique index over one column, as RecordTableSync makes it (a Short column on MySQL). */
    private static function createContractUnique(PdoDriver $driver, string $type, string $field): void
    {
        $grammar = Grammars::for($driver->pdo());
        $types = $grammar->columnTypes($driver->pdo(), $type);
        $driver->pdo()->exec($grammar->createRecordIndex($grammar->indexName('uniq', $type, $field), $type, [$field], true, $types));
    }
}
