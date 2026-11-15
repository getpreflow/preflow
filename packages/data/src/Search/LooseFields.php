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
     * Whether loose search may look at a type field: not when it says "searchable": false,
     * and not when it is hidden unless it says "searchable": true.
     */
    public static function searchable(TypeFieldDefinition $field): bool
    {
        if ($field->searchable !== null) {
            return $field->searchable;
        }

        return ($field->config['hidden'] ?? false) !== true;
    }
}
