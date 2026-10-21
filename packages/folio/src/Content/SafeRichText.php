<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Folio\Block\BlockRegistry;
use Preflow\Folio\Field\FieldTypeRegistry;

/**
 * Rich text on its way to a template: every richtext field of a record or block,
 * nested blocks included, through the richtext field type's sanitizer. Values are
 * sanitized when the admin saves them, but content can also arrive by import, app
 * code or older data, and templates print rich text with |raw.
 */
final class SafeRichText
{
    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly BlockRegistry $blocks,
    ) {}

    /**
     * @param array<string, mixed> $values a record's or block's values (localized or locale maps)
     * @param array<string, object> $fields field definitions with a "type" property
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
            } elseif ($type === 'blocks' && is_array($values[$name])) {
                $values[$name] = array_map(
                    fn (mixed $block): mixed => is_array($block) ? $this->block($block) : $block,
                    $values[$name],
                );
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
