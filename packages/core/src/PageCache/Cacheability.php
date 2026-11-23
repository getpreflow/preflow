<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Whether the page cache would store a page, and if not, why: `pages:why` and the test assertion
 * ask it. The page is rendered as a rebuild renders it (Rebuild::request(), an anonymous GET) and
 * rendered once more as another visitor, and the two are compared exactly as `page_cache.verify`
 * compares them (PageCacheMiddleware does both, in its probe mode). Nothing is stored, served or
 * recorded, so it works with `app.debug` on, where the cache itself does not run.
 *
 * Built from the container, as the Refresher is.
 *
 * @since 0.85.0
 */
final class Cacheability
{
    /** What to do about a page whose two renders differ. */
    public const string DIFFERS_HINT = 'Move the per-visitor content into a live block ({% live %} in a template, @live in Blade) or into a #[Live] component: it is then filled in for each visitor, and the rest of the page can be cached.';

    /**
     * What a refusal means and what usually fixes it: code => [reason, hint]. A code the middleware
     * adds later is still reported, with its word alone.
     *
     * @var array<string, array{0: string, 1: ?string}>
     */
    private const array REFUSALS = [
        'key' => ['the URL has no cache key (a host the cache does not cache, a query string while cache_query is off, or a forwarded header it does not accept)', null],
        'non-public' => ['the path is not a public page (the cache never stores it)', null],
        'restore' => ['the render is a hypermedia client\'s history restore, which is never stored', null],
        'kind' => ['the render is not of the kind its key stands for (a full page against a fragment)', null],
        'app-key' => ['app.key cannot sign (empty, shorter than 32 bytes, or a public example key), so nothing is stored', 'Set a long random app.key in .env (php preflow key:generate).'],
        'stream' => ['the response body is streamed and cannot be read twice', null],
        'set-cookie' => ['the response sets a cookie, which a stored page would hand to every visitor or never to the one who needs it', 'Set the cookie from a route that is not a cached page (a form post), or only for the visitor who acts; never while rendering a page for everyone.'],
        'session' => ['a session was started while the page rendered', 'Read the session only in a live block or a #[Live] component: reading it in the page opens it for every visitor.'],
        'signed-in' => ['a guard resolved a signed-in user', 'Cached pages are for anonymous visitors; show who is signed in in a live block or a #[Live] component.'],
        'context' => ['the page rendered under a context that may not be stored (working copies, a viewer, frozen time)', null],
        'cache-control' => ['the response says Cache-Control: private or no-store', 'Take the private/no-store header off the page, unless it should stay uncached.'],
        'vary' => ['the response varies on a request header the cache key does not hold', 'Name the header in page_cache.vary_headers, or stop the page varying on it.'],
        'content-type' => ['the response is not text, JSON, XML or JavaScript', null],
        'size' => ['the body is larger than the cache stores', null],
        'live-non-html' => ['the body holds live placeholders but is not HTML (they are filled into HTML only)', 'Use live blocks in HTML pages only.'],
        'index' => ['the page cache index cannot be used (pdo_sqlite missing, or the index unreadable)', null],
        'pending' => ['an invalidation that could not reach the index is still pending', 'Run php preflow pages:work, or clear the pages cache.'],
        'invalidated' => ['a write changed what the page read while it rendered', null],
        'error' => ['the page could not be written or its state could not be captured (see the log)', null],
    ];

    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $render renders a probe request through the application as a rebuild
     * @param string|null $origin app.url: the host a path is resolved against
     * @param (\Closure(): list<string>)|null $locales the site's locales, to refuse an unknown one
     */
    public function __construct(
        private readonly \Closure $render,
        private readonly ?string $origin = null,
        private readonly ?\Closure $locales = null,
    ) {}

    /**
     * @param string $path a path ('/about') or an http(s) URL of the site
     * @param string|null $locale the locale to render in, the site's default when null
     * @throws \InvalidArgumentException a path or URL that is not one, or a locale the site does not have
     */
    public function check(string $path, ?string $locale = null): CacheabilityResult
    {
        $url = trim($path);
        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && $this->origin !== null && $this->origin !== '') {
            $url = rtrim($this->origin, '/') . $url;
        }
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) || parse_url($url, PHP_URL_HOST) === null) {
            throw new \InvalidArgumentException("Not a path or URL of this site: '{$path}'.");
        }
        $locales = $this->locales !== null ? ($this->locales)() : [];
        if ($locale !== null && $locales !== [] && !in_array($locale, $locales, true)) {
            throw new \InvalidArgumentException("The site has no locale '{$locale}' (" . implode(', ', $locales) . ').');
        }

        $probe = new CacheProbe();
        $request = Rebuild::request($url, $locale !== null ? ['locale' => $locale] : [])
            ->withAttribute(PageCacheMiddleware::PROBE, $probe);
        try {
            $response = ($this->render)($request);
        } catch (\Throwable $e) {
            return new CacheabilityResult(false, 'error: the page failed to render (' . $e->getMessage() . ')');
        }
        $status = $probe->status ?? $response->getStatusCode();

        if (!$probe->reached) {
            return new CacheabilityResult(false, 'The page cache did not take part in this render (is it switched on for this site?).', null, $status);
        }
        if ($probe->status === null && $probe->code === null) {
            // The render did not finish in the middleware: the application answered for it (a missing page, an error).
            $probe->code = $status === 200 ? 'error' : 'status';
        }
        if ($probe->code === null) {
            return new CacheabilityResult(true, null, null, $status);
        }
        if ($probe->code === 'differs') {
            return new CacheabilityResult(false, 'differs per visitor outside live parts' . ($probe->difference !== null ? ' (' . $probe->difference . ')' : ''), self::DIFFERS_HINT, $status);
        }
        if ($probe->code === 'status') {
            return new CacheabilityResult(false, "status: the response is {$status}, not 200 (only 200 pages are stored)", $status === 404 ? 'No page answers at this path.' : null, $status);
        }
        if (str_starts_with($probe->code, 'refused:')) {
            return new CacheabilityResult(false, $probe->code . ': code or a store check asked for this page not to be stored (PageCache::refuse())', null, $status);
        }
        [$reason, $hint] = self::REFUSALS[$probe->code] ?? [null, null];

        return new CacheabilityResult(false, $probe->code . ($reason !== null ? ': ' . $reason : ''), $hint, $status);
    }
}
