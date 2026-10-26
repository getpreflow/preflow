<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * The translation keys of a model file's display texts, in a group: "site" for a site's own
 * types, a package's group for the types it ships (lang/{locale}/{group}.php):
 *
 *     {group}.types.{type}.label                    "Apps"
 *     {group}.types.{type}.singular                 "App" (one record: delete plans, counts)
 *     {group}.fields.{type}.{field}.label
 *     {group}.fields.{type}.{field}.help
 *     {group}.options.{type}.{field}.{value}        a select option
 *     {group}.sections.{section}                    a sidebar section a type names
 *
 * A key wins over the model file's text, which is a plain string or a map per language
 * ("label": {"en": "Apps", "de": "Anwendungen"}; "label_singular" likewise).
 */
final class ModelLabelKeys
{
    public static function type(string $group, string $type): string
    {
        return "{$group}.types.{$type}.label";
    }

    public static function singular(string $group, string $type): string
    {
        return "{$group}.types.{$type}.singular";
    }

    public static function field(string $group, string $type, string $field): string
    {
        return "{$group}.fields.{$type}.{$field}.label";
    }

    public static function help(string $group, string $type, string $field): string
    {
        return "{$group}.fields.{$type}.{$field}.help";
    }

    /** Null for a value a key can't name (empty, or with a dot): such an option keeps the file's text. */
    public static function option(string $group, string $type, string $field, string $value): ?string
    {
        if ($value === '' || str_contains($value, '.')) {
            return null;
        }

        return "{$group}.options.{$type}.{$field}.{$value}";
    }

    public static function section(string $group, string $section): string
    {
        return "{$group}.sections.{$section}";
    }

    /**
     * Every key a model file's texts can be translated with, and the text the file gives for
     * it (a string, a map per language, or null where the file has none: the type's or a
     * field's label, which then falls back to a humanized name). Hidden fields are left out;
     * a help key only where the file has a help text; a select's options one by one.
     *
     * @param array<string, mixed> $schema a model file
     * @return array<string, string|array<string, string>|null>
     */
    public static function derive(array $schema, string $group): array
    {
        $type = (string) ($schema['key'] ?? '');
        if ($type === '') {
            return [];
        }
        $keys = [
            self::type($group, $type) => self::text($schema['label'] ?? null),
            self::singular($group, $type) => self::text($schema['label_singular'] ?? null),
        ];
        foreach (is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $name => $field) {
            if (!is_string($name) || !is_array($field) || ($field['hidden'] ?? false) === true) {
                continue;
            }
            $keys[self::field($group, $type, $name)] = self::text($field['label'] ?? null);
            $help = self::text($field['help'] ?? null);
            if ($help !== null) {
                $keys[self::help($group, $type, $name)] = $help;
            }
            if (($field['type'] ?? null) === 'select' && is_array($field['options'] ?? null)) {
                $options = $field['options'];
                foreach ($options as $value => $label) {
                    $value = array_is_list($options) ? (is_scalar($label) ? (string) $label : '') : (string) $value;
                    $key = self::option($group, $type, $name, $value);
                    if ($key !== null) {
                        $keys[$key] = self::text($label);
                    }
                }
            }
        }

        return $keys;
    }

    /** @return string|array<string, string>|null */
    private static function text(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }
        $map = array_filter($value, static fn ($text, $locale): bool => is_string($text) && is_string($locale), ARRAY_FILTER_USE_BOTH);

        return $map === [] ? null : $map;
    }
}
