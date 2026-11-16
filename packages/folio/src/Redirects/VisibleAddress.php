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
     * @param string $defaultLocale the site's default language (its addresses carry no prefix by default)
     * @param bool   $prefixed      whether URLs carry the locale (i18n url_strategy "prefix")
     * @param (\Closure(string $locale): string)|null $prefixOf the site's prefix of a locale ("/de";
     *        "/en" for the default with prefix_default: FrontendResolver::localePrefix()); null:
     *        "/{locale}" for every language but the default
     */
    public function __construct(
        private string $defaultLocale,
        private bool $prefixed,
        private ?\Closure $prefixOf = null,
    ) {}

    public function of(string $locale, string $path): string
    {
        return self::readable($this->withPrefix($locale, $path));
    }

    /** The locale's prefix, then the path. */
    private function withPrefix(string $locale, string $path): string
    {
        if ($locale === '' || !$this->prefixed) {
            return $path;
        }
        $prefix = $this->prefixOf !== null
            ? ($this->prefixOf)($locale)
            : ($locale === $this->defaultLocale ? '' : '/' . $locale);

        return $prefix === '' ? $path : $prefix . ($path === '/' ? '' : $path);
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
