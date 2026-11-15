<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\FieldKinds;
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
}
