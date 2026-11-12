<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/** Whether a redirect's URL target is one: an http(s) URL with a host, or a path on this site. */
final class RedirectUrl
{
    public const int MAX_LENGTH = 2000;

    /**
     * No whitespace or control characters, at most MAX_LENGTH long; an http(s) URL with a host,
     * or a path starting with "/" (never "//host" or "/\host", which browsers read as another host).
     */
    public static function isValid(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            $host = parse_url($url, PHP_URL_HOST);

            return is_string($host) && $host !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false;
        }

        return str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_starts_with($url, '/\\');
    }
}
