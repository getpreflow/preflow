<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Data\Schema\RecordTables;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * Code-built companions of a publishing type: the published snapshots
 * ({table}__published, same fields and ids) and the revisions
 * ({table}__revisions, one full copy per publish). They are not JSON models,
 * so they never appear in the admin's type list.
 *
 * A snapshot keeps its type's key (only the table differs): it is the same
 * content type, so routes, URLs and type templates resolve as for the record.
 *
 * @phpstan-import-type Entry from RecordTables
 */
final class ShadowTypes
{
    /**
     * Their tables: the snapshots typed like the record's table but without unique indexes (they
     * hold copies), the revisions free text (history must never block a conversion).
     *
     * @param list<string> $extra columns besides the fields
     * @return list<Entry>
     */
    public static function tables(TypeDefinition $def, array $extra = []): array
    {
        return [
            RecordTables::entry(self::published($def), $extra, unique: false),
            RecordTables::entry(self::revisions($def), $extra, typed: false, unique: false),
        ];
    }

    public static function published(TypeDefinition $def): TypeDefinition
    {
        return new TypeDefinition(
            key: $def->key,
            table: $def->table . '__published',
            storage: $def->storage,
            fields: $def->fields,
            idField: $def->idField,
            searchableFields: $def->searchableFields,
            transformers: $def->transformers,
            matrixable: $def->matrixable,
            views: $def->views,
            locales: $def->locales,
        );
    }

    public static function revisions(TypeDefinition $def): TypeDefinition
    {
        $fields = [];
        foreach (['record_id' => 'string', 'seq' => 'number', 'data' => 'text', 'changed' => 'text', 'created_at' => 'string', 'created_by' => 'string'] as $name => $type) {
            $fields[$name] = new TypeFieldDefinition($name, $type);
        }

        return new TypeDefinition(
            key: $def->key . '__revisions',
            table: $def->table . '__revisions',
            storage: $def->storage,
            fields: $fields,
            idField: 'uuid',
        );
    }
}
