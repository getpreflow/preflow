<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

/** sitemap.xml: one `<url>` per page and locale, with hreflang alternates. */
final class Sitemap
{
    /** @param array<string, list<PublicPage>> $pagesByLocale locale => pages */
    public static function xml(array $pagesByLocale, string $origin, string $defaultLocale): string
    {
        $origin = rtrim($origin, '/');
        $seen = [];
        $urls = '';

        foreach ($pagesByLocale as $pages) {
            foreach ($pages as $page) {
                if (!$page->sitemap) {
                    continue;
                }
                $loc = self::absolute($page->url, $origin);
                if (isset($seen[$loc])) {
                    continue;
                }
                $seen[$loc] = true;

                $urls .= "  <url>\n    <loc>" . self::e($loc) . "</loc>\n";
                if ($page->lastmod !== null && $page->lastmod !== '') {
                    $urls .= '    <lastmod>' . self::e($page->lastmod) . "</lastmod>\n";
                }
                foreach ($page->alternates as $locale => $url) {
                    $urls .= self::link((string) $locale, self::absolute($url, $origin));
                }
                if (isset($page->alternates[$defaultLocale])) {
                    $urls .= self::link('x-default', self::absolute($page->alternates[$defaultLocale], $origin));
                }
                $urls .= "  </url>\n";
            }
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n"
            . $urls
            . "</urlset>\n";
    }

    private static function link(string $hreflang, string $href): string
    {
        return '    <xhtml:link rel="alternate" hreflang="' . self::e($hreflang) . '" href="' . self::e($href) . "\"/>\n";
    }

    private static function absolute(string $url, string $origin): string
    {
        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1 ? $url : $origin . '/' . ltrim($url, '/');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
