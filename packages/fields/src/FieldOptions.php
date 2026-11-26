<?php

declare(strict_types=1);

namespace Preflow\Fields;

/**
 * The boot check of the options of every field type of this package, in one place:
 * FieldsServiceProvider runs checkFields() over every type's fields at boot (with or without
 * Folio, kept in the boot manifest) and over every block's fields when the block registry is
 * built; Folio's type catalog calls check() too.
 *
 * Each type refuses what it cannot use with a message naming the option and what to write
 * instead. None of these types is translatable: a phone number, an address, a colour, code, JSON,
 * a video and a place are the same in every language (a link translates its label and a table its
 * cells inside the value instead).
 *
 * @since 0.91.0
 */
final class FieldOptions
{
    /** The field types of this package that take options or refuse "translatable". */
    public const array TYPES = ['link', 'email', 'phone', 'color', 'code', 'json', 'datetime', 'time', 'daterange', 'embed', 'table', 'location'];

    /**
     * The types a show_when may only test with "empty" or "filled": a structured value (an object)
     * and a datetime or time, which the browser (the field's local text) and the server (the stored
     * value) cannot compare the same way.
     */
    public const array PRESENCE_ONLY = ['link', 'phone', 'table', 'daterange', 'embed', 'location', 'datetime', 'time'];

    /**
     * Checks the fields of one type or block: each field of a type of this package (check()), and
     * each show_when naming a field of a PRESENCE_ONLY type with another operator than "empty" or
     * "filled". The message starts with the field (`field "f": …`); the caller puts the type or
     * block in front. A show_when that is malformed is left to the data package's own check.
     *
     * @param array<string, array<string, mixed>|\Preflow\Data\TypeFieldDefinition> $fields a model file's fields, or a block's field definitions
     * @throws \LogicException
     */
    public static function checkFields(array $fields, ?EmbedProviders $embedProviders = null): void
    {
        $types = [];
        foreach ($fields as $name => $field) {
            [$type, $config] = self::typeAndConfig($field);
            $types[(string) $name] = $type;
            if (!in_array($type, self::TYPES, true)) {
                continue;
            }
            try {
                self::check($type, $config, $embedProviders);
            } catch (\LogicException $e) {
                throw new \LogicException("field \"{$name}\": " . $e->getMessage(), 0, $e);
            }
        }
        foreach (\Preflow\Data\FieldVisibility::rulesOf($fields) as $name => $rule) {
            try {
                $comparisons = is_array($rule) ? \Preflow\Data\FieldVisibility::fromConfig($rule, $types)->comparisons() : [];
            } catch (\Preflow\Data\ShowWhenException) {
                continue;
            }
            foreach ($comparisons as [$source, $operator]) {
                $type = $types[$source] ?? null;
                if ($type === null || !in_array($type, self::PRESENCE_ONLY, true) || in_array($operator, ['empty', 'filled'], true)) {
                    continue;
                }
                throw new \LogicException("field \"{$name}\": show_when compares \"{$source}\" with \"{$operator}\", but \"{$source}\" is " . self::article($type) . " {$type} field, which show_when can only test with \"empty\" or \"filled\" (the browser and the server cannot compare its value the same way). Write {\"field\": \"{$source}\", \"filled\": true} or {\"field\": \"{$source}\", \"empty\": true}.");
            }
        }
    }

    /**
     * @param array<string, mixed>|\Preflow\Data\TypeFieldDefinition $field
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function typeAndConfig(array|\Preflow\Data\TypeFieldDefinition $field): array
    {
        if ($field instanceof \Preflow\Data\TypeFieldDefinition) {
            return [$field->type, ['translatable' => $field->translatable] + $field->config];
        }
        $type = $field['type'] ?? 'string';

        return [is_string($type) ? $type : 'string', $field];
    }

    /**
     * @param array<string, mixed> $config the field's config, with its "translatable" as a key
     * @param EmbedProviders|null $embedProviders the app's video hosts, when the caller has them: an embed field listing an unregistered one is refused
     * @throws \LogicException
     */
    public static function check(string $type, array $config, ?EmbedProviders $embedProviders = null): void
    {
        match ($type) {
            'link' => LinkOptions::check($config),
            'phone' => PhoneOptions::check($config),
            'color' => ColorOptions::check($config),
            'code' => CodeOptions::check($config),
            'datetime', 'time', 'daterange' => DateOptions::check($type, $config),
            'embed' => EmbedOptions::check($config, $embedProviders),
            'table' => TableOptions::check($config),
            'location' => self::checkLocation($config),
            'email', 'json' => self::refuseTranslatable($type, $config),
            default => null,
        };
    }

    /**
     * Refuses the options of $type given as an object under its own name (the shape of the link
     * field's `"link": {…}`): these types take theirs at the field's top level, and a nested
     * object would be ignored without a word.
     *
     * @param array<string, mixed> $config
     * @param list<string> $options the type's option names
     */
    public static function refuseNested(string $type, array $config, array $options): void
    {
        if (is_array($config[$type] ?? null)) {
            $names = implode(', ', array_map(static fn (string $o): string => "\"{$o}\"", $options));
            throw new \LogicException("\"{$type}\" holds options, but " . self::article($type) . " {$type} field takes them at its top level, next to \"type\": move {$names} out of \"{$type}\".");
        }
    }

    /** @param array<string, mixed> $config */
    public static function refuseTranslatable(string $type, array $config): void
    {
        if (($config['translatable'] ?? false) === true) {
            throw new \LogicException(ucfirst(self::article($type)) . " {$type} field cannot be translatable; it holds the same value in every language. Remove \"translatable\".");
        }
    }

    /**
     * A location field takes no options of its own (a map comes from a LocationPicker).
     *
     * @param array<string, mixed> $config
     */
    private static function checkLocation(array $config): void
    {
        self::refuseTranslatable('location', $config);
        if (array_key_exists('location', $config)) {
            throw new \LogicException('"location" holds options, but a location field takes none; remove "location". A map comes from a LocationPicker the site binds.');
        }
    }

    private static function article(string $type): string
    {
        return str_contains('aeiou', $type[0]) ? 'an' : 'a';
    }
}
