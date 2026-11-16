<?php

declare(strict_types=1);

namespace Preflow\Data\Search;

use Preflow\Data\FieldKinds;
use Preflow\Data\Relation;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * The fields of a type that loose search looks at: its own text fields, in field order. A field that
 * says "searchable": false is left out, and so is a hidden one unless it says "searchable": true.
 * A field marked exact ("exact": true) holds a machine value, not searchable text: no word search,
 * LIKE or full-text index looks into it (since 0.78); loose search finds a record when a word of the
 * term equals such a field's whole value, compared exactly (exact(), conditions()), on every driver.
 * Relations are not text (their targets' titles are searched through the relation). This is the one
 * list QueryBuilder::searchLoose() searches, ranked search asks for, and a full-text index covers.
 *
 * @since 0.78
 */
final class LooseFields
{
    /**
     * @return list<string>
     */
    public static function text(TypeDefinition $def, FieldKinds $kinds): array
    {
        $text = [];
        foreach ($def->fields as $name => $field) {
            if (!self::searchable($field) || Relation::fromField($def->key, $field, $kinds) !== null) {
                continue;
            }
            if ($kinds->isText($field->type)) {
                $text[] = $name;
            }
        }

        return $text;
    }

    /**
     * The exact text fields loose search compares whole (a word equal to the value), in field
     * order: fields marked exact that are visible to it (wholeValue()), text kinds, no relations.
     *
     * @return list<string>
     * @since 0.78
     */
    public static function exact(TypeDefinition $def, FieldKinds $kinds): array
    {
        $exact = [];
        foreach ($def->fields as $name => $field) {
            if (self::wholeValue($field) && $kinds->isText($field->type) && Relation::fromField($def->key, $field, $kinds) === null) {
                $exact[] = $name;
            }
        }

        return $exact;
    }

    /**
     * Whether loose search may look into a type field's words: not a field marked exact (a machine
     * value, since 0.78), not when it says "searchable": false, and not when it is hidden unless
     * it says "searchable": true.
     */
    public static function searchable(TypeFieldDefinition $field): bool
    {
        return !$field->exact && self::visible($field);
    }

    /**
     * Whether loose search compares a field's whole value with each word, exactly: a field marked
     * exact that searchable() would otherwise take.
     *
     * @since 0.78
     */
    public static function wholeValue(TypeFieldDefinition $field): bool
    {
        return $field->exact && self::visible($field);
    }

    /**
     * One word's conditions for whereAny(): CONTAINS (the text-matching rule) on each text field,
     * = (exact) on each field compared whole. The one place that says how a word meets a field.
     *
     * @param list<string> $text
     * @param list<string> $whole
     * @return list<array{field: string, operator: string, value: string}>
     * @since 0.78
     */
    public static function conditions(array $text, array $whole, string $word): array
    {
        $conditions = [];
        foreach ($text as $name) {
            $conditions[] = ['field' => $name, 'operator' => 'CONTAINS', 'value' => $word];
        }
        foreach ($whole as $name) {
            $conditions[] = ['field' => $name, 'operator' => '=', 'value' => $word];
        }

        return $conditions;
    }

    private static function visible(TypeFieldDefinition $field): bool
    {
        if ($field->searchable !== null) {
            return $field->searchable;
        }

        return ($field->config['hidden'] ?? false) !== true;
    }
}
