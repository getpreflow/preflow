<?php

declare(strict_types=1);

namespace Preflow\I18n;

/**
 * A language's name in that language ("Deutsch", "Français"), as a language switch shows it:
 * everyone finds their own language whatever the page's language is.
 */
final class LocaleNames
{
    private const array NATIVE = [
        'en' => 'English',
        'de' => 'Deutsch',
        'fr' => 'Français',
        'it' => 'Italiano',
        'es' => 'Español',
        'pt' => 'Português',
        'nl' => 'Nederlands',
        'rm' => 'Rumantsch',
        'da' => 'Dansk',
        'sv' => 'Svenska',
        'nb' => 'Norsk bokmål',
        'pl' => 'Polski',
        'cs' => 'Čeština',
    ];

    /** The listed name, else ext-intl's (capitalized), else the code in capitals. */
    public static function native(string $locale): string
    {
        $locale = strtolower($locale);
        if (isset(self::NATIVE[$locale])) {
            return self::NATIVE[$locale];
        }
        if (class_exists(\Locale::class)) {
            $name = (string) \Locale::getDisplayLanguage($locale, $locale);
            if ($name !== '' && strtolower($name) !== $locale) {
                return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
            }
        }

        return strtoupper($locale);
    }
}
