<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * Code-built companions of a publishing type: the published snapshots
 * ({table}__published, same fields and ids) and the revisions
 * ({table}__revisions, one full copy per publish). They are not JSON models,
 * so they never appear in the admin's type list.
 */
final class ShadowTypes
{
    public static function published(TypeDefinition $def): TypeDefinition
    {
        return new TypeDefinition(
            key: $def->key . '__published',
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
