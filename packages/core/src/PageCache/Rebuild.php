<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

use Nyholm\Psr7\ServerRequest;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\Http\Session\SessionMiddleware;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The request that rebuilds a queued page: an anonymous GET of its URL, marked as an internal
 * render (it leaves no trace) and as a rebuild (PageCacheMiddleware::REBUILD: never served from
 * the cache, stored), with no cookies, credentials or session.
 *
 * It carries the key parts the page was stored under (PageKey::parts()), so it renders under
 * the key visitors read:
 * - proto, host: X-Forwarded-Proto and X-Forwarded-Host;
 * - locale: Accept-Language, which the locale middleware resolves when the URL has no prefix
 *   (url_strategy none, or the default locale); a prefix in the URL wins anyway;
 * - vary: the vary header values (fragments; they are not queued, but a refresh of one could be).
 *
 * With EXPECTED_ID the middleware stores only when the request gives that key, and logs when it
 * doesn't (a key part the request can't reproduce): a rebuild never stores under another key.
 */
final class Rebuild
{
    /** Request attribute: the page id the rebuild must store under. */
    public const string EXPECTED_ID = 'preflow.page_cache.rebuild_id';

    /** Request attribute: true when the page was stored (so verified) in this release: verify 'first' skips it. */
    public const string VERIFIED = 'preflow.page_cache.rebuild_verified';

    /**
     * @param array<string, mixed> $parts PageKey::parts()
     * @param string|null $expectedId the page id (PageKey::id()), null for a URL the index doesn't know
     */
    public static function request(string $url, array $parts = [], ?string $expectedId = null, bool $verified = false): ServerRequestInterface
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME)) ?: 'http';
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');

        $headers = ['Accept' => 'text/html'];
        foreach (['proto' => 'X-Forwarded-Proto', 'host' => 'X-Forwarded-Host', 'locale' => 'Accept-Language'] as $part => $header) {
            if (is_string($parts[$part] ?? null) && $parts[$part] !== '') {
                $headers[$header] = $parts[$part];
            }
        }
        foreach (is_array($parts['vary'] ?? null) ? $parts['vary'] : [] as $name => $value) {
            if (is_string($name) && is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }

        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path . ($query !== '' ? '?' . $query : ''),
            'QUERY_STRING' => $query,
            'SERVER_NAME' => $host,
            'SERVER_PORT' => (string) $port,
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        if ($scheme === 'https') {
            $server['HTTPS'] = 'on';
        }
        parse_str($query, $params);

        $request = (new ServerRequest('GET', $url, $headers, null, '1.1', $server))
            ->withQueryParams($params)
            ->withAttribute(SessionMiddleware::WITHOUT_SESSION, true)
            ->withAttribute(PageCacheMiddleware::REBUILD, true)
            ->withAttribute(self::VERIFIED, $verified);
        if ($expectedId !== null) {
            $request = $request->withAttribute(self::EXPECTED_ID, $expectedId);
        }

        return InternalRender::mark($request);
    }
}
