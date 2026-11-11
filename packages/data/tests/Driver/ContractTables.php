<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;

/**
 * StorageDriverContract::createType() for the SQL drivers: the record table the schema grammar
 * creates for a type (as RecordTableSync does), or, for a number id, one with an auto-increment
 * key.
 */
trait ContractTables
{
    /** @param array<string, 'string'|'number'|'boolean'|'json'> $fields the id field first */
    private static function createContractTable(PdoDriver $driver, string $type, array $fields, string $autoIdColumn): void
    {
        $idField = array_key_first($fields);
        $specs = [];
        foreach (array_slice($fields, 1, null, true) as $name => $kind) {
            $specs[] = new ColumnSpec($name, match ($kind) {
                'number' => ColumnKind::Number,
                'boolean' => ColumnKind::Boolean,
                'string', 'json' => ColumnKind::Text,
            });
        }
        $grammar = Grammars::for($driver->pdo());

        if ($fields[$idField] !== 'number') {
            $driver->pdo()->exec($grammar->createRecordTable($type, $idField, $specs));

            return;
        }
        $quote = $driver->dialect()->quoteIdentifier(...);
        $columns = array_map(static fn (ColumnSpec $c): string => $quote($c->name) . ' ' . $grammar->recordColumnType($c->kind), $specs);
        $driver->pdo()->exec('CREATE TABLE ' . $quote($type) . ' (' . $quote($idField) . " {$autoIdColumn}, " . implode(', ', $columns) . ')');
    }
}
