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
 * dropped, the rest are decoded, re-encoded and sorted by base name, the part before "[" (all
 * values of one base name keep their order: a[]=2&a[]=1 is not a[]=1&a[]=2, a[1]=x&a[0]=y is not
 * a[0]=y&a[1]=x, since PHP builds those arrays in a different order).
 *
 * Only the hosts the config caches (app.url's, `hosts`) get a key. A HEAD request shares GET's
 * key, so it can be answered from the cache, but its (empty) response is never stored:
 * PageStore::put() refuses a HEAD key.
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
        private bool $head,
        private array $parts = [],
    ) {}

    /**
     * The key of $request, or null when a request of its shape is never cached: not GET/HEAD
     * (HEAD shares GET's key), a host the config does not cache, or a query string while
     * cache_query is off. Whether
     * the visitor is signed in is not decided here.
     *
     * @param array<string, string> $parts more of what the response depends on, name => value
     *        (the page cache middleware adds the locale LocaleMiddleware resolved and the
     *        forwarded scheme/host); none gives the same id as before they existed
     */
    public static function fromRequest(ServerRequestInterface $request, PageCacheConfig $config, array $parts = []): ?self
    {
        $method = strtoupper($request->getMethod());
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }

        $uri = $request->getUri();
        $host = strtolower($uri->getHost());
        if ($host === '' || !$config->cachesHost($host)) {
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
        $kept = $parts;
        $varyValues = [];
        foreach ($vary as $name => $line) {
            $varyValues[$name] = substr($line, strlen($name) + 1);
        }
        if ($varyValues !== []) {
            $kept['vary'] = $varyValues;
        }
        ksort($kept, SORT_STRING);
        $extra = [];
        if ($parts !== []) {
            ksort($parts, SORT_STRING);
            foreach ($parts as $name => $value) {
                $extra[] = '@' . $name . '=' . $value;
            }
        }
        $id = sha1(implode("\n", [$origin, $path, $query, ...array_values($vary), ...$extra]));

        return new self($id, $url, $fragment, $query !== '', $method === 'HEAD', $kept);
    }

    /**
     * What the key holds besides the URL, so a rebuild can ask for the same key
     * (Rebuild::request()): the $parts given (the middleware's locale, proto, host) and, under
     * 'vary', the values of the vary headers the request carried (lowercase name => value).
     *
     * @return array<string, mixed>
     */
    public function parts(): array
    {
        return $this->parts;
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

    /** A HEAD request: may be served from the cache, never stored. */
    public function isHead(): bool
    {
        return $this->head;
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
            $bracket = strpos($name, '[');
            $base = $bracket === false || $bracket === 0 ? $name : substr($name, 0, $bracket);
            $pairs[] = [$base, rawurlencode($name) . '=' . rawurlencode(urldecode($value))];
        }
        // usort is stable: the values of one base name (a, a[], a[x]) keep their order.
        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return implode('&', array_column($pairs, 1));
    }
}
