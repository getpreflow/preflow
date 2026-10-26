<?php

declare(strict_types=1);

namespace Preflow\I18n;

/**
 * Display texts that are a plain string or a map per language, as model files write
 * them ("label": "Apps" or "label": {"en": "Apps", "de": "Anwendungen"}).
 */
final class Labels
{
    /**
     * A string is itself; a map gives the locale's entry, else the default locale's, else
     * English, else the first; null (or an empty map) gives null.
     *
     * @param string|array<string, string>|null $value
     */
    public static function pick(string|array|null $value, string $locale, string $default): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }
        foreach ([$locale, $default, 'en'] as $candidate) {
            if (isset($value[$candidate]) && is_string($value[$candidate])) {
                return $value[$candidate];
            }
        }
        foreach ($value as $text) {
            if (is_string($text)) {
                return $text;
            }
        }

        return null;
    }

    /**
     * The translator's key in its current locale wins (an admin override, the site's
     * language file, a package file); then the given text picked for that locale with the
     * site default; with no text given, the key along the translator's fallback chain;
     * else null.
     *
     * @param string|array<string, string>|null $value
     */
    public static function resolve(Translator $translator, string $key, string|array|null $value): ?string
    {
        if ($translator->has($key)) {
            return $translator->get($key);
        }
        $picked = self::pick($value, $translator->getLocale(), $translator->getFallbackLocale());
        if ($picked !== null) {
            return $picked;
        }
        $text = $translator->get($key);

        return $text === $key ? null : $text;
    }
}
