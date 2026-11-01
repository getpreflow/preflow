<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What GEO and SEO both read off a request or response: whether a page is
 * kept out of search, the site's origin, and absolute URLs.
 *
 * @internal shared by GeoMiddleware and SeoMiddleware; not a public API
 */
final class PageSignals
{
    /** Whether an `X-Robots-Tag` header forbids indexing. */
    public static function noindexHeader(ResponseInterface $response): bool
    {
        foreach ($response->getHeader('X-Robots-Tag') as $value) {
            if (self::noindexes($value)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the page has a `<meta name="robots">` that forbids indexing. */
    public static function noindexMeta(string $html): bool
    {
        preg_match_all('/<meta\b[^>]*>/i', $html, $tags);
        foreach ($tags[0] as $tag) {
            if (
                preg_match('/\bname\s*=\s*(["\']?)robots\1(?=[\s>\/])/i', $tag) === 1
                && preg_match('/\bcontent\s*=\s*(["\'])(.*?)\1/is', $tag, $content) === 1
                && self::noindexes($content[2])
            ) {
                return true;
            }
        }

        return false;
    }

    /** Whether a robots directive list contains noindex (or none). */
    public static function noindexes(string $directives): bool
    {
        $tokens = preg_split('/[\s,:]+/', strtolower($directives), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return in_array('noindex', $tokens, true) || in_array('none', $tokens, true);
    }

    /** The configured app.url's origin when it is absolute, else the request's. */
    public static function origin(?string $appUrl, ServerRequestInterface $request): string
    {
        $parts = $appUrl !== null && trim($appUrl) !== '' ? parse_url(trim($appUrl)) : false;
        if (is_array($parts) && isset($parts['scheme'], $parts['host']) && in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }

        $uri = $request->getUri();

        return $uri->getHost() !== ''
            ? $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '')
            : '';
    }

    /** $url made absolute against $origin (absolute and protocol-relative URLs kept). */
    public static function absolute(string $url, string $origin): string
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return (parse_url($origin, PHP_URL_SCHEME) ?: 'https') . ':' . $url;
        }

        return $origin . '/' . ltrim($url, '/');
    }
}
