<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldVisibility;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\FieldTypeRegistry;

/**
 * A list cell as plain text: what the admin list shows and the Excel export writes. The field
 * type's admin display with the markup stripped (a list cell never renders field HTML raw);
 * relation columns show their targets' labels. A field the record's show_when hides is an empty
 * cell (since 0.91.0).
 */
final class ListCells
{
    /**
     * @param array<string, array<string, string>> $relationLabels field => id => label (RelationLabels::forRecords); a field in it is a relation column
     * @param DisplayLabels|null                   $labels         select options in the interface language (null: the model file's texts)
     */
    public static function text(FieldTypeRegistry $fieldTypes, TypeDefinition $def, string $name, DynamicRecord $record, array $relationLabels = [], ?DisplayLabels $labels = null): string
    {
        if (self::hidden($def, $name, $record)) {
            return '';
        }
        $localized = $record->localized($def->locales->default);
        if (isset($relationLabels[$name])) {
            $ids = RelationLabels::ids($localized->get($name));

            return implode(', ', array_filter(array_map(static fn (string $id) => $relationLabels[$name][$id] ?? null, $ids), static fn (?string $l) => $l !== null));
        }

        $fieldDef = $def->fields[$name];
        $type = $fieldTypes->get($fieldDef->type);

        $config = $fieldDef->config;
        if ($labels !== null && $fieldDef->type === 'select' && is_array($config['options'] ?? null)) {
            $config['options'] = $labels->options($def->key, $name); // option labels in the interface language
        }

        return self::plain(self::display($type, $type->fromStorage($localized->get($name)), $config));
    }

    /**
     * Whether the record's show_when hides the field: its cell is empty (the list and its Excel
     * export), the stored value stays.
     *
     * @since 0.91.0
     */
    public static function hidden(TypeDefinition $def, string $name, DynamicRecord $record): bool
    {
        // Only a field with a rule of its own can hide (a field naming a hidden one has a rule too).
        if (!isset($def->fields[$name]->config['show_when'])) {
            return false;
        }

        return in_array($name, FieldVisibility::hiddenFieldsOf($def, $record->toArray()), true);
    }

    /** Field types whose cells are numbers: the list sets them in mono, right-aligned. */
    private const array NUMBER_TYPES = ['number', 'int', 'integer', 'float'];

    /**
     * What kind of value a column shows: "number" (a number field: mono, right-aligned), "relation"
     * (linked records' labels) or "text" (everything else).
     *
     * @since 0.75
     */
    public static function kind(TypeDefinition $def, string $name): string
    {
        $type = $def->fields[$name]->type ?? '';

        return match (true) {
            in_array($type, self::NUMBER_TYPES, true) => 'number',
            $type === 'relation' => 'relation',
            default => 'text',
        };
    }

    /** The field type's admin display (list cells, read-only fields): renderAdmin() when it has one, else renderFrontend(). */
    public static function display(FieldType $type, mixed $value, array $config): string
    {
        return $type instanceof DisplaysInAdmin ? $type->renderAdmin($value, $config) : $type->renderFrontend($value, $config);
    }

    public static function plain(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
