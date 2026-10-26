<?php

declare(strict_types=1);

namespace Preflow\I18n;

/**
 * The browser's preferred languages (the Accept-Language header) matched against the
 * offered two-letter locales: "fr-CH,fr;q=0.9" picks "fr".
 */
final class AcceptLanguage
{
    /**
     * The offered locale the header ranks highest, or null (no header, nothing offered matches).
     *
     * @param list<string> $offered
     */
    public static function best(string $header, array $offered): ?string
    {
        if (trim($header) === '') {
            return null;
        }

        $languages = [];
        foreach (explode(',', $header) as $part) {
            $segments = explode(';', trim($part));
            $lang = strtolower(trim($segments[0]));
            $q = 1.0;
            if (isset($segments[1]) && preg_match('/q=([0-9.]+)/', $segments[1], $m)) {
                $q = (float) $m[1];
            }
            if ($lang === '' || $q <= 0.0) {
                continue;
            }
            // Just the language: de-DE -> de
            $short = explode('-', $lang)[0];
            $languages[$short] = max($languages[$short] ?? 0.0, $q);
        }
        arsort($languages);

        foreach (array_keys($languages) as $lang) {
            if (in_array((string) $lang, $offered, true)) {
                return (string) $lang;
            }
        }

        return null;
    }
}
