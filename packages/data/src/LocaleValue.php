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
        $candidate = self::decodeObject($raw);
        if (is_array($candidate) && self::looksLikeLocaleMap($candidate, $locales)) {
            return $candidate;
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
        $candidate = self::decodeObject($raw);

        return is_array($candidate) && self::looksLikeLocaleMap($candidate, $locales)
            ? ($candidate[$locales->default] ?? null)
            : $raw;
    }

    /** A stored JSON object string (SQL drivers return maps as JSON text) decoded; anything else unchanged. */
    private static function decodeObject(mixed $raw): mixed
    {
        if (is_string($raw) && str_starts_with(ltrim($raw), '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $raw;
    }

    /**
     * A stored translatable value: an object whose keys all look like locale
     * codes, at least one of them configured. (A locale removed from config
     * later still reads as part of the map, so no content is lost.)
     *
     * @param array<mixed> $value
     */
    private static function looksLikeLocaleMap(array $value, Locales $locales): bool
    {
        if ($value === [] || array_is_list($value)) {
            return false;
        }
        $known = false;
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $key) !== 1) {
                return false;
            }
            $known = $known || $locales->has($key);
        }

        return $known;
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
