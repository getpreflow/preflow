<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\FieldKinds;
use Preflow\Data\ModelMetadata;
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
     * The text fields (of()) stored in a bounded, indexable column (ColumnKind::Short: unique or
     * indexed strings and selects, not translatable), as RecordTableSync types them. A database may
     * order such a column by its collation where a long text (TEXT, LONGTEXT) needs more
     * (TextComparison::textOrder()), and keep using its index.
     *
     * @return list<string>
     * @since 0.78
     */
    public static function short(TypeDefinition $def, FieldKinds $kinds): array
    {
        $indexed = $def->indexedByOthers();
        $short = [];
        foreach (self::of($def, $kinds) as $name) {
            $field = $def->fields[$name];
            if (in_array($name, $indexed, true) && !$field->index) {
                $field = $field->withIndex();
            }
            if ($kinds->columnKindOf($field) === ColumnKind::Short) {
                $short[] = $name;
            }
        }

        return $short;
    }

    /**
     * The fields of a full-text type ("fullText": true) that compare exactly although a full-text
     * index puts them in a column of the rule's collation: the loosely searchable text fields
     * (LooseFields::text()) that are not text fields (of()), that is lists such as tags. Fields
     * marked exact are no searchable text (LooseFields leaves them out), so their columns stay
     * exact. A full-text index takes columns of one collation only (MySQL), so their columns carry
     * the rule's; queries compare them exactly (Query::compareExactly()). Empty for a type without
     * full-text search.
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
            $type = $field->type ?? self::propertyType($meta->modelClass, $name);
            if ($type !== null && $name !== $meta->idField && !$field->exact && $kinds->isText($type) && !$kinds->isList($type)) {
                $text[] = $name;
            }
        }

        return $text;
    }

    /**
     * The text fields of a plain model class (ofModel()) in a bounded, indexable column: those whose
     * #[Field] is unique or indexed (not translatable, not of type "text"). A plain model's table
     * comes from its migration, which can index only a bounded column (string(); MySQL refuses an
     * index over text()), so these are VARCHAR there. Any other one is sorted the safe way
     * (Query::shortText()).
     *
     * @return list<string>
     * @since 0.78
     */
    public static function shortOfModel(ModelMetadata $meta, FieldKinds $kinds): array
    {
        $short = [];
        foreach (self::ofModel($meta, $kinds) as $name) {
            $field = $meta->fields[$name];
            if (($field->unique !== false || $field->index) && !$field->translatable && $field->type !== 'text') {
                $short[] = $name;
            }
        }

        return $short;
    }

    /** "string" for a property typed string (or ?string), else null (not text). */
    private static function propertyType(string $class, string $property): ?string
    {
        if (!property_exists($class, $property)) {
            return null;
        }
        $type = (new \ReflectionProperty($class, $property))->getType();

        return $type instanceof \ReflectionNamedType && $type->getName() === 'string' ? 'string' : null;
    }
}
