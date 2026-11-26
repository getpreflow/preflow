<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Data\FieldVisibility;
use Preflow\Data\TypeFieldDefinition;

/**
 * The editor's markup for a conditional field (show_when, 0.91): the attributes of the element
 * around the field, which fields.js reads to show or hide it as its source changes
 * (show-when-core.js decides as FieldVisibility does). It carries the rule, the types of the
 * fields it names (a number compares as whole numbers), their current values (for a source
 * without a control, such as a read-only field) and the prefix of a block card's names (its
 * source "kind" is posted as "<prefix>[kind]"). A field hidden now is rendered hidden, so it
 * does not flash before the script runs. Values and checks stay in FieldVisibility.
 *
 * @internal
 */
final class ShowWhenMarkup
{
    /**
     * @param array<mixed> $rule the field's show_when (checked when the registry was built)
     * @param array<string, TypeFieldDefinition> $fields the fields of the record or block
     * @param array<string, mixed> $values the current values (each in the default language)
     */
    public static function attributes(array $rule, array $fields, array $values, string $prefix, bool $hidden): string
    {
        $types = [];
        foreach ($fields as $name => $field) {
            $types[(string) $name] = $field->type;
        }
        $sources = FieldVisibility::fromConfig($rule, $types)->sources();
        $config = [
            'rule' => $rule,
            'types' => (object) array_intersect_key($types, array_flip($sources)),
            'values' => (object) array_intersect_key($values, array_flip($sources)),
            'prefix' => $prefix,
        ];
        $json = (string) json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return ' data-folio-show-when="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '"'
            . ($hidden ? ' hidden' : ''); // hidden alone takes it out of the reading and tab order
    }

    /**
     * A block card's field inside its own element with attributes().
     *
     * @param array<mixed> $rule
     * @param array<string, TypeFieldDefinition> $fields
     * @param array<string, mixed> $values
     */
    public static function wrap(string $html, array $rule, array $fields, array $values, string $prefix, bool $hidden): string
    {
        return '<div class="folio-show-when"' . self::attributes($rule, $fields, $values, $prefix, $hidden) . '>' . $html . '</div>';
    }
}
