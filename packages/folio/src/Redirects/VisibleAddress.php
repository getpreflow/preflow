<?php

declare(strict_types=1);

namespace Preflow\Folio\Redirects;

/**
 * A stored address (locale and path in RedirectPath's form) as visitors see and type it:
 * "/de/über-uns" for the path "/%C3%BCber-uns" in German on a site with locale prefixes.
 * Settings → Redirects and the editor's "Old addresses" note show addresses this way.
 */
final readonly class VisibleAddress
{
    /**
     * @param string $defaultLocale the site's default language (its addresses carry no prefix)
     * @param bool   $prefixed      whether URLs carry the locale (i18n url_strategy "prefix")
     */
    public function __construct(
        private string $defaultLocale,
        private bool $prefixed,
    ) {}

    public function of(string $locale, string $path): string
    {
        return self::readable($this->withPrefix($locale, $path));
    }

    /** The locale prefix of a language other than the default, then the path. */
    private function withPrefix(string $locale, string $path): string
    {
        if ($locale === '' || $locale === $this->defaultLocale || !$this->prefixed) {
            return $path;
        }

        return '/' . $locale . ($path === '/' ? '' : $path);
    }

    /**
     * Each segment decoded ("/über-uns"), except for the characters that would change the
     * path when typed back ("%", "/", "?", "#").
     */
    private static function readable(string $path): string
    {
        return implode('/', array_map(
            static fn (string $segment): string => str_replace(['%', '/', '?', '#'], ['%25', '%2F', '%3F', '%23'], rawurldecode($segment)),
            explode('/', $path),
        ));
    }
}
