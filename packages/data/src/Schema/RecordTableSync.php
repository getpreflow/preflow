<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\Schema;
use Preflow\Data\TypeDefinition;

/**
 * The table of one SQL-stored type: created when missing, missing columns
 * added. The id is the primary key and every other column is nullable text
 * (the grammar's createRecordTable). Nothing is ever dropped or altered;
 * JSON-stored definitions are left alone.
 */
final class RecordTableSync
{
    public function __construct(private readonly DataManager $dm) {}

    /**
     * @param list<string> $extraColumns columns besides the definition's fields
     * @return list<string> the changes made, one line each
     */
    public function sync(TypeDefinition $def, array $extraColumns = []): array
    {
        $driver = $this->dm->driver($def->storage);
        if (!$driver instanceof PdoDriver) {
            return [];
        }

        $pdo = $driver->pdo();
        $schema = new Schema($pdo);
        $columns = array_values(array_unique([...array_keys($def->fields), ...$extraColumns]));
        $columns = array_values(array_diff($columns, [$def->idField]));

        if (!$schema->hasTable($def->table)) {
            $pdo->exec(Grammars::for($pdo)->createRecordTable($def->table, $def->idField, $columns));

            return ["created table {$def->table}"];
        }

        $lines = [];
        $existing = $schema->columns($def->table);
        foreach ($columns as $column) {
            if (!in_array($column, $existing, true)) {
                $schema->addColumn($def->table, $column);
                $lines[] = "added column {$def->table}.{$column}";
            }
        }

        return $lines;
    }
}
