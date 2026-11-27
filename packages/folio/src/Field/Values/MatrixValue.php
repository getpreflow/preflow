<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Values;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Data\Values\ListsReferences;

/**
 * A "matrix" field's value (Folio's ordered, polymorphic reference list; 0.93), shape "json": a
 * list of {"_type", "id"} references, each with an optional "view". Stored as its JSON text, as
 * Folio's form stores it since the field exists; read out as the list (refs()). An entry without
 * a type or an id is left out; the view is kept as given (the render checks it). references()
 * names each referenced type and its ids. Which types a field allows is the form's (MatrixFieldType).
 *
 * @since 0.93.0
 */
final class MatrixValue implements FieldValue, DescribesShapes, ListsReferences
{
    /** The refs' JSON text; what holds no refs (no list, its text or anything else) is "[]", as Folio stored it. */
    public function toStored(mixed $input, TypeFieldDefinition $field): string
    {
        return (string) json_encode(self::refs($input), JSON_UNESCAPED_SLASHES);
    }

    /** @return list<array{_type: string, id: string, view?: string}> */
    public function toOutput(mixed $stored, TypeFieldDefinition $field): array
    {
        return self::refs($stored);
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'json';
    }

    public function shapes(): array
    {
        return \Preflow\Fields\Values\Field\StoredShapes::shapes('json');
    }

    public function references(mixed $stored, TypeFieldDefinition $field): array
    {
        $out = [];
        foreach (self::refs($stored) as $ref) {
            if (!in_array($ref['id'], $out[$ref['_type']] ?? [], true)) {
                $out[$ref['_type']][] = $ref['id'];
            }
        }

        return $out;
    }

    /**
     * A stored or given value (the list, or its JSON text) as its references, each with a
     * non-empty view kept. The view is not checked against the type's views here: the form does
     * that on save, and the render's own guard stays the backstop.
     *
     * @return list<array{_type: string, id: string, view?: string}>
     */
    public static function refs(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = (string) ($entry['_type'] ?? '');
            $id = (string) ($entry['id'] ?? '');
            if ($type === '' || $id === '') {
                continue;
            }
            $ref = ['_type' => $type, 'id' => $id];
            $view = (string) ($entry['view'] ?? '');
            if ($view !== '') {
                $ref['view'] = $view;
            }
            $out[] = $ref;
        }

        return $out;
    }
}
