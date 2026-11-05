<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

/**
 * URLs as the built-in checks compare them: an href resolved against its page,
 * and a key that treats two spellings of one page (percent-encoded or not, a
 * trailing slash, the host's case, http or https) as the same.
 *
 * @internal
 * @since 0.54
 */
final class Urls
{
    /**
     * $href (as written in a page) as an absolute http(s) URL without fragment, resolved against $base (the
     * page's absolute URL); null for an empty href, a bare fragment or another scheme (mailto:, data:, …).
     */
    public static function resolve(string $href, string $base): ?string
    {
        $href = trim($href);
        $hash = strpos($href, '#');
        if ($hash !== false) {
            $href = substr($href, 0, $hash);
        }
        if ($href === '') {
            return null;
        }

        $scheme = (string) parse_url($base, PHP_URL_SCHEME);
        if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', $href, $m) === 1) {
            return in_array(strtolower($m[1]), ['http', 'https'], true) ? $href : null;
        }
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }

        $origin = $scheme . '://' . parse_url($base, PHP_URL_HOST) . (($port = parse_url($base, PHP_URL_PORT)) !== null ? ':' . $port : '');
        $query = '';
        $mark = strpos($href, '?');
        if ($mark !== false) {
            $query = substr($href, $mark);
            $href = substr($href, 0, $mark);
        }
        if ($href === '') {
            $path = (string) parse_url($base, PHP_URL_PATH);
        } elseif (str_starts_with($href, '/')) {
            $path = $href;
        } else {
            $basePath = (string) parse_url($base, PHP_URL_PATH);
            $path = substr($basePath, 0, (int) strrpos('/' . ltrim($basePath, '/'), '/')) . '/' . $href;
        }

        return $origin . self::withoutDots($path) . $query;
    }

    /** The lower-case host of an absolute URL; '' when it has none. */
    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /** The path of an absolute URL ('/' when empty), as written. */
    public static function path(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $path === '' ? '/' : $path;
    }

    /** The key two spellings of the same page share: host and decoded path, without a trailing slash. */
    public static function key(string $url): string
    {
        $path = rtrim(rawurldecode(self::path($url)), '/');

        return self::host($url) . ($path === '' ? '/' : $path);
    }

    /** $path with "." and ".." segments resolved (RFC 3986 5.2.4). */
    private static function withoutDots(string $path): string
    {
        $out = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            if ($segment === '.' || $segment === '..') {
                if ($segment === '..' && count($out) > 1) {
                    array_pop($out);
                }
                if ($i === $last) {
                    $out[] = '';
                }
                continue;
            }
            $out[] = $segment;
        }

        $resolved = implode('/', $out);

        return str_starts_with($resolved, '/') ? $resolved : '/' . $resolved;
    }
}
