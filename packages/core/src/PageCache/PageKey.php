<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The cache key of a request: scheme + host + path + the normalised query + the values of the
 * configured vary headers (HX-Request, HX-Target, ...). The locale is part of the path (/de/x,
 * /fr/x), so it is part of the key.
 *
 * The query is normalised: parameters matching an ignore_query pattern (utm_*, fbclid, ...) are
 * dropped, the rest are decoded, re-encoded and sorted by name (values of a repeated name keep
 * their order, a[]=2&a[]=1 is not a[]=1&a[]=2).
 *
 * A history-restore request (HX-History-Restore-Request) asks for a full page: its key leaves the
 * HX-* headers out, so it is the same key as a plain visit, and isFragment() is false.
 */
final readonly class PageKey
{
    private function __construct(
        private string $id,
        private string $url,
        private bool $fragment,
        private bool $hasQuery,
    ) {}

    /**
     * The key of $request, or null when a request of its shape is never cached: not GET/HEAD
     * (HEAD shares GET's key), no host, or a query string while cache_query is off. Whether
     * the visitor is signed in is not decided here.
     */
    public static function fromRequest(ServerRequestInterface $request, PageCacheConfig $config): ?self
    {
        $method = strtoupper($request->getMethod());
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }

        $uri = $request->getUri();
        $host = strtolower($uri->getHost());
        if ($host === '') {
            return null;
        }
        $scheme = strtolower($uri->getScheme()) ?: 'http';
        $port = $uri->getPort();
        $origin = $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
        $path = $uri->getPath();
        $path = $path === '' ? '/' : $path;

        $query = self::normaliseQuery($uri->getQuery(), $config->ignoreQuery);
        if ($query !== '' && !$config->cacheQuery) {
            return null;
        }

        $restore = $request->getHeaderLine('HX-History-Restore-Request') === 'true';
        $fragment = !$restore && $request->getHeaderLine('HX-Request') === 'true';

        $vary = [];
        foreach ($config->varyHeaders as $header) {
            $name = strtolower($header);
            if ($restore && str_starts_with($name, 'hx-')) {
                continue; // a history restore is the full page
            }
            $value = $request->getHeaderLine($header);
            if ($value !== '') {
                $vary[$name] = $name . ':' . $value;
            }
        }
        ksort($vary, SORT_STRING);

        $url = $origin . $path . ($query !== '' ? '?' . $query : '');
        $id = sha1(implode("\n", [$origin, $path, $query, ...array_values($vary)]));

        return new self($id, $url, $fragment, $query !== '');
    }

    /** 40 hex characters. */
    public function id(): string
    {
        return $this->id;
    }

    /** The absolute URL with the normalised query: what a rebuild renders. */
    public function url(): string
    {
        return $this->url;
    }

    /** An htmx request for part of a page (not a history restore). */
    public function isFragment(): bool
    {
        return $this->fragment;
    }

    /** Whether the normalised query is not empty (search and filter pages are rebuilt only on visit). */
    public function hasQuery(): bool
    {
        return $this->hasQuery;
    }

    /** @param list<string> $ignore fnmatch() patterns, matched without regard to case */
    private static function normaliseQuery(string $query, array $ignore): string
    {
        if ($query === '') {
            return '';
        }
        $patterns = array_map(strtolower(...), $ignore);
        $pairs = [];
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $name = urldecode($name);
            if ($name === '') {
                continue;
            }
            $lower = strtolower($name);
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $lower)) {
                    continue 2;
                }
            }
            $pairs[] = [$name, rawurlencode($name) . '=' . rawurlencode(urldecode($value))];
        }
        // usort is stable: repeated names keep their order.
        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return implode('&', array_column($pairs, 1));
    }
}
