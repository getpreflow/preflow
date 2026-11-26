<?php

declare(strict_types=1);

namespace Preflow\Data\Validation;

use Preflow\Data\FieldKinds;
use Preflow\Data\ModelLabelKeys;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Validation\Rules\InList;
use Preflow\Validation\Rules\WhenFilled;
use Preflow\Validation\ValidationRule;

/**
 * A select's value must be one of its options: an in-list rule of the "options" values (a value =>
 * label map, a plain list where each value is its label, or {value: {label, help}}), or, for
 * "options_from", OptionsFrom on the source field among $siblings (the other fields of the record's
 * type, or of the block). DataManager runs these for a type's selects on every validated write
 * (Folio's form, imports, seeds, REST), BlockValueChecks for a block's; nothing for a field that
 * is no select or has no options. An empty value (null or "") passes; "required" judges that.
 *
 * @since 0.92.0
 */
final class SelectRules
{
    /**
     * @param array<string, TypeFieldDefinition> $siblings the fields beside it (the record's type's, or the block's)
     * @param FieldKinds|null $kinds tells whether the source holds a list (a comma text of tags gives its entries)
     * @return list<string|ValidationRule>
     */
    public static function of(TypeFieldDefinition $field, array $siblings, ?FieldKinds $kinds = null): array
    {
        if ($field->type !== 'select') {
            return [];
        }
        $source = $field->config['options_from'] ?? null;
        if (is_string($source) && $source !== '') {
            $sourceDef = $siblings[$source] ?? null;

            return [new OptionsFrom(
                $source,
                $sourceDef !== null ? ($sourceDef->label ?? self::humanize($source)) : null,
                $sourceDef !== null && $kinds !== null && $kinds->holdsList($sourceDef),
            )];
        }
        $values = self::values($field->config['options'] ?? null);

        // An empty choice passes, as Folio's form gives it ("required" judges that).
        return $values === [] ? [] : [new WhenFilled(new InList($values))];
    }

    /**
     * A field name as a label: "possible_answers" → "Possible Answers" (as Folio's forms humanize it).
     */
    public static function humanize(string $name): string
    {
        $name = str_replace('_', ' ', $name);
        $name = (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);
        $name = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $name);

        return ucwords($name);
    }

    /**
     * The values of "options": the keys of a map, the entries of a plain list.
     *
     * @return list<string>
     */
    private static function values(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }
        if (!array_is_list($options)) {
            return array_map('strval', array_keys($options));
        }
        $values = [];
        foreach ($options as $option) {
            $label = ModelLabelKeys::isDescribedOption($option) ? $option['label'] : $option;
            if (is_scalar($label)) {
                $values[] = (string) $label;
            }
        }

        return $values;
    }
}
