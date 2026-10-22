<?php

declare(strict_types=1);

namespace Preflow\Folio\Storage;

use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\Schema;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\ShadowTypes;

/**
 * Tables of SQL-stored Folio types, from their model JSON (the `folio.tables`
 * step of `preflow migrate`): the type's table, the published and revision
 * shadows of publishing types, the asset table when it is SQL-stored, and
 * Folio's own internal tables it is given (the extension install state).
 * The id is TEXT PRIMARY KEY, every other column nullable TEXT. Missing
 * tables and columns are added; nothing is ever dropped or altered.
 * JSON-stored types are left alone.
 */
final class TableSync
{
    /** System keys Folio writes besides a type's fields. */
    private const array SYSTEM = ['_locales', '_order', ...Publisher::META];

    /** @param list<TypeDefinition> $internal */
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly ?TypeDefinition $assets = null,
        private readonly array $internal = [],
    ) {}

    /** @return list<string> the changes made, one line each */
    public function sync(): array
    {
        $lines = [];
        foreach ($this->definitions() as $def) {
            $driver = $this->dm->driver($def->storage);
            if (!$driver instanceof PdoDriver) {
                continue;
            }
            if ($driver->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                throw new \LogicException('Folio table sync supports SQLite only for now');
            }
            array_push($lines, ...$this->syncTable($driver->pdo(), $def));
        }

        return $lines;
    }

    /** @return list<TypeDefinition> */
    private function definitions(): array
    {
        $defs = [];
        foreach ($this->catalog->all() as $listing) {
            $def = $this->registry->get($listing->key);
            $defs[] = $def;
            if ($listing->publishing) {
                $defs[] = ShadowTypes::published($def);
                $defs[] = ShadowTypes::revisions($def);
            }
        }
        if ($this->assets !== null) {
            $defs[] = $this->assets;
        }
        array_push($defs, ...$this->internal);

        return $defs;
    }

    /** @return list<string> */
    private function syncTable(\PDO $pdo, TypeDefinition $def): array
    {
        $schema = new Schema($pdo);
        $columns = array_values(array_unique([...array_keys($def->fields), ...self::SYSTEM]));
        $columns = array_values(array_diff($columns, [$def->idField]));

        if (!$schema->hasTable($def->table)) {
            $sql = array_map(static fn (string $c): string => "\"{$c}\" TEXT", $columns);
            $pdo->exec("CREATE TABLE \"{$def->table}\" (\"{$def->idField}\" TEXT PRIMARY KEY, " . implode(', ', $sql) . ')');

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
