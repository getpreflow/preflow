<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\FieldTypeRegistry;

/**
 * A list cell as plain text: what the admin list shows and the Excel export writes. The field
 * type's admin display with the markup stripped (a list cell never renders field HTML raw);
 * relation columns show their targets' labels.
 */
final class ListCells
{
    /**
     * @param array<string, array<string, string>> $relationLabels field => id => label (RelationLabels::forRecords); a field in it is a relation column
     * @param DisplayLabels|null                   $labels         select options in the interface language (null: the model file's texts)
     */
    public static function text(FieldTypeRegistry $fieldTypes, TypeDefinition $def, string $name, DynamicRecord $record, array $relationLabels = [], ?DisplayLabels $labels = null): string
    {
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
