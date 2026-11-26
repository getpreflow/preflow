<?php

declare(strict_types=1);

namespace Preflow\Fields;

/**
 * The boot check of the options of every field type of this package, in one place: Folio's type
 * catalog and block definitions call check() for a field of a type from here.
 *
 * Each type refuses what it cannot use with a message naming the option and what to write
 * instead. None of these types is translatable: a phone number, an address, a colour, code and JSON are the same in every language
 * (a link translates its label inside the value instead).
 *
 * @since 0.91.0
 */
final class FieldOptions
{
    /** The field types of this package that take options or refuse "translatable". */
    public const array TYPES = ['link', 'email', 'phone', 'color', 'code', 'json', 'datetime', 'time', 'daterange'];

    /**
     * @param array<string, mixed> $config the field's config, with its "translatable" as a key
     * @throws \LogicException
     */
    public static function check(string $type, array $config): void
    {
        match ($type) {
            'link' => LinkOptions::check($config),
            'phone' => PhoneOptions::check($config),
            'color' => ColorOptions::check($config),
            'code' => CodeOptions::check($config),
            'datetime', 'time', 'daterange' => DateOptions::check($type, $config),
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
            throw new \LogicException("\"{$type}\" holds options, but a {$type} field takes them at its top level, next to \"type\": move {$names} out of \"{$type}\".");
        }
    }

    /** @param array<string, mixed> $config */
    public static function refuseTranslatable(string $type, array $config): void
    {
        if (($config['translatable'] ?? false) === true) {
            throw new \LogicException((str_contains('aeiou', $type[0]) ? 'An' : 'A') . " {$type} field cannot be translatable; it holds the same value in every language. Remove \"translatable\".");
        }
    }
}
