<?php

declare(strict_types=1);

namespace Preflow\Members;

/** Names of the site's locales: in their own language (the member's choice) and in English (the admin). */
final class Languages
{
    private const array OWN = ['en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'it' => 'Italiano', 'es' => 'Español', 'nl' => 'Nederlands', 'pt' => 'Português', 'rm' => 'Rumantsch'];
    private const array ENGLISH = ['en' => 'English', 'de' => 'German', 'fr' => 'French', 'it' => 'Italian', 'es' => 'Spanish', 'nl' => 'Dutch', 'pt' => 'Portuguese', 'rm' => 'Romansh'];

    /** @param list<string> $locales @return array<string, string> locale => own name */
    public static function options(array $locales): array
    {
        $options = [];
        foreach ($locales as $locale) {
            $options[$locale] = self::OWN[$locale] ?? strtoupper($locale);
        }

        return $options;
    }

    public static function english(string $locale): string
    {
        return self::ENGLISH[$locale] ?? strtoupper($locale);
    }
}
