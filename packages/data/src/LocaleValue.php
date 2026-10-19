<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * Reading translatable values: a translatable field stores a locale map
 * ({"en": …, "de": …}). Plain values are tolerated in both directions, so a
 * field can change its "translatable" flag without migrating data.
 */
final class LocaleValue
{
    /**
     * The locale map of a stored value. A scalar (or a JSON string that is not
     * an object) counts as the default locale's value.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $raw, Locales $locales): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (is_string($raw) && str_starts_with(ltrim($raw), '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !array_is_list($decoded)) {
                return $decoded;
            }
        }
        if (is_array($raw) && !array_is_list($raw)) {
            return $raw;
        }

        return [$locales->default => $raw];
    }

    /** The value stored for exactly this locale (no fallback). */
    public static function pick(mixed $raw, Locales $locales, string $locale): mixed
    {
        return self::map($raw, $locales)[$locale] ?? null;
    }

    /**
     * The value for a locale following the fallback chain, and whether it came
     * from another locale. Null and '' count as missing.
     *
     * @return array{0: mixed, 1: bool}
     */
    public static function resolve(mixed $raw, Locales $locales, string $locale): array
    {
        $map = self::map($raw, $locales);
        foreach ($locales->chain($locale) as $candidate) {
            $value = $map[$candidate] ?? null;
            if ($value !== null && $value !== '') {
                return [$value, $candidate !== $locale];
            }
        }

        return [null, true];
    }

    /** Read tolerance for a non-translatable field: a stored locale map yields its default value. */
    public static function default(mixed $raw, Locales $locales): mixed
    {
        return is_array($raw) && self::isLocaleMap($raw, $locales) ? ($raw[$locales->default] ?? null) : $raw;
    }

    /**
     * Resolves locale maps anywhere in nested data (block content): an array
     * whose keys are all configured locale codes is a translatable value;
     * every other array is walked.
     */
    public static function resolveTree(mixed $value, Locales $locales, string $locale): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (self::isLocaleMap($value, $locales)) {
            return self::resolve($value, $locales, $locale)[0];
        }

        return array_map(static fn ($item) => self::resolveTree($item, $locales, $locale), $value);
    }

    /** @param array<mixed> $value */
    public static function isLocaleMap(array $value, Locales $locales): bool
    {
        if ($value === [] || array_is_list($value)) {
            return false;
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !$locales->has($key)) {
                return false;
            }
        }

        return true;
    }
}
