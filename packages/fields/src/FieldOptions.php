<?php

declare(strict_types=1);

namespace Preflow\Fields;

/**
 * The boot check of the options of every field type of this package, in one place: Folio's type
 * catalog and block definitions call check() for a field of a type from here.
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
