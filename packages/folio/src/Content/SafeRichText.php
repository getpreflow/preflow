<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Blocks\BlockLists;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\BlockTextCleaner;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Folio\Field\FieldTypeRegistry;

/**
 * Rich text on its way to a template: every richtext field of a record or block,
 * nested blocks included (a blocks field's, an areas field's columns: BlockLists), through the
 * richtext field type's sanitizer. Values are
 * sanitized when the admin saves them, but content can also arrive by import, app
 * code or older data, and templates print rich text with |raw. Blocks ask it as their BlockTextCleaner.
 */
final class SafeRichText implements BlockTextCleaner
{
    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly BlockRegistry $blocks,
    ) {}

    /**
     * @param array<string, mixed> $values a record's or block's values (localized or locale maps)
     * @param array<string, object> $fields field definitions with a "type" property (TypeFieldDefinition for those holding blocks)
     * @return array<string, mixed>
     */
    public function fields(array $values, array $fields): array
    {
        foreach ($fields as $name => $field) {
            if (!array_key_exists($name, $values)) {
                continue;
            }
            $type = (string) ($field->type ?? '');
            if ($type === 'richtext') {
                $values[$name] = $this->sanitize($values[$name]);
            } elseif ($field instanceof TypeFieldDefinition && BlockLists::holds($field) && is_array($values[$name])) {
                // Every list of blocks the field holds (a blocks field, the areas of an areas field).
                $values[$name] = BlockLists::map($field, $values[$name], fn (array $list): array => array_map($this->block(...), $list));
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    public function block(array $block): array
    {
        $definition = $this->blocks->get((string) ($block['_block'] ?? ''));

        return $definition !== null ? $this->fields($block, $definition->fields) : $block;
    }

    /** A string, or a locale map of strings (blocks rendered before locale resolution). */
    private function sanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->fieldTypes->get('richtext')->renderFrontend($value, []);
        }
        if (is_array($value)) {
            return array_map(fn (mixed $v): mixed => is_string($v) ? $this->sanitize($v) : $v, $value);
        }

        return $value;
    }
}
