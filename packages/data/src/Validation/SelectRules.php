<?php

declare(strict_types=1);

namespace Preflow\Data\Validation;

use Preflow\Core\Context\Contexts;
use Preflow\Data\FieldKinds;
use Preflow\Data\FieldLabels;
use Preflow\Data\ModelLabelKeys;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Validation\Rules\InList;
use Preflow\Validation\Rules\WhenFilled;
use Preflow\Validation\ValidationRule;

/**
 * A select's value must be one of its options: an in-list rule of the "options" values, or, for
 * "options_from", OptionsFrom on the source field among $siblings (the other fields of the record's
 * type, or of the block). DataManager runs these for a type's selects on every validated write
 * (Folio's form, imports, seeds, REST), BlockValueChecks for a block's; nothing for a field that
 * is no select or has no options. An empty value (null or "") passes; "required" judges that.
 *
 * Every shape of "options" Folio's select takes gives the values its form offers: a value => label
 * map (labels as text, a map per language, or {label, help}) gives its keys; a plain list gives each
 * entry's label as its value (text, {label, help}, or a map per language: the label in the current
 * render's language, as the form shows it).
 *
 * @since 0.92.0
 */
final class SelectRules
{
    /**
     * @param array<string, TypeFieldDefinition> $siblings the fields beside it (the record's type's, or the block's)
     * @param FieldKinds|null  $kinds  tells whether the source holds a list (a comma text of tags gives its entries)
     * @param FieldLabels|null $labels names the source in the "no choices yet" message (DataManager::fieldLabels())
     * @param TypeDefinition|null $type the record type the fields belong to; null for a block's
     * @return list<string|ValidationRule>
     */
    public static function of(TypeFieldDefinition $field, array $siblings, ?FieldKinds $kinds = null, ?FieldLabels $labels = null, ?TypeDefinition $type = null): array
    {
        if ($field->type !== 'select') {
            return [];
        }
        $locale = self::locale();
        $source = $field->config['options_from'] ?? null;
        if (is_string($source) && $source !== '') {
            $sourceDef = $siblings[$source] ?? null;

            return [new OptionsFrom(
                $source,
                $sourceDef === null ? null : ($labels?->fieldLabel($sourceDef, $locale, $type) ?? self::labelOf($sourceDef, $locale, $type, $labels?->fallbackLocale())),
                $sourceDef !== null && $kinds !== null && $kinds->holdsList($sourceDef),
            )];
        }
        $values = self::values($field->config['options'] ?? null, $locale, $labels?->fallbackLocale());

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
     * The text of a label given as text or as a map per language, as the admin's forms pick it: the
     * locale's entry, else the fallback locale's (FieldLabels::fallbackLocale(), the site's), else
     * English's, else the first.
     *
     * @param string|array<array-key, mixed>|null $label
     */
    public static function pick(string|array|null $label, string $locale, ?string $fallback = null): ?string
    {
        if ($label === null || is_string($label)) {
            return $label;
        }
        foreach ([$locale, $fallback ?? 'en', 'en'] as $candidate) {
            if (is_string($label[$candidate] ?? null)) {
                return $label[$candidate];
            }
        }
        foreach ($label as $text) {
            if (is_string($text)) {
                return $text;
            }
        }

        return null;
    }

    /** Without FieldLabels: a record field's label (its map per language), a block field's label, else its name humanized. */
    private static function labelOf(TypeFieldDefinition $field, string $locale, ?TypeDefinition $type, ?string $fallback): string
    {
        $label = $type !== null ? self::pick($field->labels ?: $field->label, $locale, $fallback) : $field->label;

        return $label ?? self::humanize($field->name);
    }

    /**
     * The values of "options": the keys of a map, the entries' labels of a plain list.
     *
     * @return list<string>
     */
    private static function values(mixed $options, string $locale, ?string $fallback): array
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
            $text = is_array($label) ? self::pick($label, $locale, $fallback) : (is_scalar($label) ? (string) $label : null);
            if ($text !== null) {
                $values[] = $text;
            }
        }

        return $values;
    }

    /**
     * The language labels are shown in, as the render's texts are: the admin's interface language on
     * the admin surface, else the render's locale; English outside every render.
     */
    private static function locale(): string
    {
        $context = Contexts::peek();

        return $context === null ? 'en' : ($context->surface->admin ? ($context->surface->uiLocale ?? $context->locale) : $context->locale);
    }
}
