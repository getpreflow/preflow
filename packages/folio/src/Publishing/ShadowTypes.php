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

    /**
     * The snapshots: the record's fields, its unique fields and their scopes plain indexed ones
     * (stored alike, never checked: a snapshot may hold a value another record has taken since).
     */
    public static function published(TypeDefinition $def): TypeDefinition
    {
        $indexed = [];
        foreach ($def->uniqueFields() as $field => $scope) {
            array_push($indexed, $field, ...$scope);
        }
        $fields = [];
        foreach ($def->fields as $name => $f) {
            $fields[$name] = $f->unique === false && !in_array($name, $indexed, true) ? $f : new TypeFieldDefinition(
                $f->name, $f->type, $f->searchable, $f->transform, $f->validate, $f->label, $f->help, $f->config,
                $f->translatable, $f->labels, $f->helps, unique: false, index: true, length: $f->length,
            );
        }

        return new TypeDefinition(
            key: $def->key,
            table: $def->table . '__published',
            storage: $def->storage,
            fields: $fields,
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
