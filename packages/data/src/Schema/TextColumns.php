<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\FieldKinds;
use Preflow\Data\ModelMetadata;
use Preflow\Data\ModelSchema;
use Preflow\Data\Search\LooseFields;
use Preflow\Data\TypeDefinition;

/**
 * The fields of a type compared by the text rule (ignore case, respect accents; TextMatch): =,
 * !=, IN, NOT IN, range comparisons, ORDER BY, grouping and unique checks. They are the fields
 * whose type is text (FieldKinds::isText(): string, text, and the text types extensions register,
 * such as rich text and select), translatable ones included, except list fields (tags), the id
 * field and fields marked exact ("exact": true, #[Field(exact: true)]). Slugs and e-mail
 * addresses are string fields and so text: "About" and "about" are one slug. Everything else
 * compares exactly: ids, relations, assets, numbers, dates, yes/no values.
 *
 * @since 0.78
 */
final class TextColumns
{
    /** @return list<string> the text fields, in field order */
    public static function of(TypeDefinition $def, FieldKinds $kinds): array
    {
        $text = [];
        foreach ($def->fields as $name => $field) {
            $name = (string) $name;
            if ($name !== $def->idField && !$field->exact && $kinds->isText($field->type) && !$kinds->isList($field->type)) {
                $text[] = $name;
            }
        }

        return $text;
    }

    /**
     * The fields of a full-text type ("fullText": true) that compare exactly although a full-text
     * index puts them in a column of the rule's collation: the loosely searchable text fields
     * (LooseFields::text()) that are not text fields (of()), that is lists such as tags. Fields
     * marked exact are no searchable text (LooseFields leaves them out), so their columns stay
     * exact. A full-text index takes columns of one collation only (MySQL), so their columns carry
     * the rule's; queries compare them exactly (QueryCompiler sees the column's collation). Empty
     * for a type without full-text search.
     *
     * @return list<string>
     * @since 0.78
     */
    public static function exactInFullText(TypeDefinition $def, FieldKinds $kinds): array
    {
        if (!$def->fullText) {
            return [];
        }

        return array_values(array_diff(LooseFields::text($def, $kinds), self::of($def, $kinds)));
    }

    /**
     * The text fields of a plain model class (#[Entity] without a type): its #[Field] properties
     * whose field type (#[Field(type: …)], else "string" for a string property, as ModelSchema
     * infers it) is text, by the same rule as of(). A class marked #[Entity(type: …)] is its
     * type's definition: use of() with it.
     *
     * @return list<string>
     */
    public static function ofModel(ModelMetadata $meta, FieldKinds $kinds): array
    {
        $text = [];
        foreach ($meta->fields as $name => $field) {
            $name = (string) $name;
            $type = ModelSchema::fieldType($meta, $name);
            if ($type !== null && $name !== $meta->idField && !$field->exact && $kinds->isText($type) && !$kinds->isList($type)) {
                $text[] = $name;
            }
        }

        return $text;
    }
}
