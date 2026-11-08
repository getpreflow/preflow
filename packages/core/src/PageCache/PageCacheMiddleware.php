<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

use Nyholm\Psr7\Response;
use Preflow\Core\Http\InternalRender;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves anonymous GET and HEAD requests from the page cache and stores what they render.
 *
 * It runs after the locale middleware (the key holds the resolved locale) and before the
 * session middleware, so a hit never opens the session: a hit reads the store's `active` name
 * and the page file, and stats the release key's inputs (once per process), the enabled marker,
 * the page's stale marker and the pending invalidations; no database, no SQLite.
 *
 * A request passes through untouched when:
 * - it has no key (not GET/HEAD, a host that isn't cached, a query while cache_query is off,
 *   an X-Forwarded-Proto other than http/https, an X-Forwarded-Host that isn't cached);
 * - it is an internal render (the SEO audit, GEO's Markdown views) that isn't a rebuild;
 * - its path is not a public page (NonPublicPaths: admin, APIs, /--component);
 * - it carries a signed-in cookie (signed_in_cookies: the auth marker and the guards'
 *   remember-me cookies) or an Authorization header: `X-Page-Cache: bypass`. A leftover
 *   session cookie alone does not bypass.
 *
 * A hit (fresh, or stale: marked, past its expiresAt or older than max_age; max_age 0 turns the
 * maximum age off) gets the stored headers and body with this request's per-request values
 * filled in, and `X-Page-Cache: hit` or `stale`; a stale GET also goes to $onStale (the rebuild
 * queue, Refresher::stale()), a stale HEAD (an uptime monitor) changes nothing. Without $onStale,
 * a stale page's file is forgotten once a GET was served it: its next visitor renders it anew (a
 * miss), so a stale page is served to one visitor only. The render state carried for middleware
 * outside (PageCache::carry(), SEO's page meta) is restored first.
 *
 * While an invalidation is pending (PendingInvalidations: it could not reach the index), there
 * is no hit and nothing is stored: $applyPending is asked to apply it first, and only when that
 * succeeds is the page served. One more stat per hit.
 *
 * A rebuild (REBUILD, Rebuild::request(); from Refresher::work()) is never served from the cache,
 * only stored. With Rebuild::EXPECTED_ID it is rendered only when it gives that key (else
 * `X-Page-Cache: rebuild-key`, logged); verify 'first' renders it twice only when its key was
 * not verified yet in this release (TagIndex::verified()). Its session check is PHP's own
 * (an active session), since the request that wrote and queued it may have had one, closed
 * before the work runs. With $rebuildOnly (the console: `preflow pages:work`) every other
 * request passes through untouched.
 *
 * A miss renders with its reads recorded (DependencyRecorder) and gets `X-Page-Cache: miss`.
 * Every render that may be stored (the miss, verify's second render, a rebuild) is isolated
 * (PageCache::isolate()): it starts from the state a public render starts from.
 * It is stored only when none of these holds; with page_cache.explain on, the reason is sent
 * as `X-Page-Cache-Skip`:
 * - `status`: the status is not 200 (404s and redirects are never cached);
 * - `restore` / `kind`: the render is not of the kind the key stands for: a full page's key
 *   rendered for a request with HX-Request (a history restore, which may be served the full
 *   page but is never stored), or a fragment's key without it;
 * - `app-key`: app.key is empty or the public default, so placeholders could be forged;
 * - `stream`: the body can't be read twice (a streamed export);
 * - `set-cookie`: the response sets a cookie (a header, or a native one through header());
 * - `session`: the session was started during the request;
 * - `signed-in`: a guard resolved a user;
 * - `cache-control`: Cache-Control is private or no-store;
 * - `vary`: the response varies on a request header the key doesn't hold (anything but
 *   vary_headers, Accept-Encoding and HX-History-Restore-Request);
 * - `content-type`: not text, JSON, XML or JavaScript;
 * - `size`: the body is larger than MAX_BODY;
 * - `refused:{reason}`: code called PageCache::refuse(), or a store check a package registered
 *   said so (PageCache::refuseWhen(); Folio's `working-copy`);
 * - `index`: the tag index can't be used (the cache is off then);
 * - `pending`: an invalidation that could not reach the index is still pending (PendingInvalidations);
 * - `invalidated`: a write changed what the page read while it rendered;
 * - `live-non-html`: the body holds live placeholders but is not text/html (they are filled
 *   into HTML only; such a response goes out as it is). One without a Content-Type is HTML;
 * - `differs`: verified (page_cache.verify: 'first' for a key not verified yet in this release,
 *   'always' for every store), the page rendered once more as another anonymous visitor
 *   (another REMOTE_ADDR, no cookies) has another body or other stored headers outside its live
 *   parts and per-request values (logged: something personal is not in a live part);
 * - `error`: the page could not be written (logged).
 *
 * Live parts (LiveParts): while a miss renders they leave signed placeholders, which are
 * stored. Every text/html response the cache answers fills them for its visitor: a hit after
 * the per-request values, and the miss itself (stored or not) before it goes out.
 *
 * Stored headers: everything the response had when it left the handler, except Set-Cookie,
 * the headers SecurityHeadersMiddleware adds per request (Content-Security-Policy(-Report-Only),
 * HX-Nonce, Strict-Transport-Security; it runs outside this middleware, so it adds them fresh
 * to every hit), and the hop and framing headers (Date, Content-Length, Transfer-Encoding,
 * Connection, Keep-Alive).
 */
final class PageCacheMiddleware implements MiddlewareInterface
{
    /** The response header: hit, stale, miss or bypass. */
    public const string HEADER = 'X-Page-Cache';

    /** Why a miss was not stored. */
    public const string SKIP_HEADER = 'X-Page-Cache-Skip';

    /** Request attribute: true on an internal render that rebuilds a page (it is stored, never served). */
    public const string REBUILD = 'preflow.page_cache.rebuild';

    /** Bodies larger than this (bytes) are not stored. */
    public const int MAX_BODY = 8 * 1024 * 1024;

    /** Response headers never stored (lowercase). */
    public const array UNSTORED_HEADERS = [
        'set-cookie', 'content-security-policy', 'content-security-policy-report-only', 'hx-nonce',
        'strict-transport-security', 'date', 'content-length', 'transfer-encoding', 'connection',
        'keep-alive', 'x-page-cache', 'x-page-cache-skip',
    ];

    /**
     * Request headers a response may vary on besides vary_headers: the key covers them. Not
     * Accept-Language: a page that negotiates on it (a redirect for some visitors) says so with
     * Vary and is not cached, unless vary_headers names it; the locale LocaleMiddleware resolved
     * from it (url_strategy none) is in the key anyway.
     */
    private const array VARY_ALLOWED = ['accept-encoding', 'hx-history-restore-request'];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private bool $checked = false;

    /** @var array<string, true> URLs whose past expiry was logged (explain) */
    private array $pastExpiries = [];

    private ?TagIndex $index = null;

    /** @var \Closure(): list<string> */
    private readonly \Closure $sentHeaders;

    /** PageCacheConfig::VERIFY_* */
    private readonly string $verify;

    /**
     * @param \Closure(): PageStore $store the store (made on first use)
     * @param \Closure(): void $clearAll empties the whole cache (when the enabled marker is missing)
     * @param (\Closure(): string)|null $localePrefix the locale prefix the locale middleware stripped ('/de'), '' for none
     * @param (\Closure(): bool)|null $signedIn whether a guard resolved a user in this request
     * @param (\Closure(): bool)|null $sessionStarted whether the session was started
     * @param (\Closure(string): bool)|null $nonPublic whether a path is not a public page
     * @param (\Closure(PageKey): void)|null $onStale gets the key of every stale page served (it
     *        rebuilds it); null: the page is forgotten once served
     * @param (\Closure(string): void)|null $log error_log() when null
     * @param (\Closure(): float)|null $clock microtime(true) when null
     * @param bool|null $sqlite a test seam, as TagIndex's
     * @param (\Closure(): list<string>)|null $sentHeaders the headers PHP queued natively; headers_list() by default
     * @param LiveParts|null $live fills the live parts of what is served
     * @param bool|string|null $verify 'first', 'always' or 'off' (true: always, false: off; config verify when null)
     * @param (\Closure(ServerRequestInterface, \Closure(): ResponseInterface): ResponseInterface)|null $visitorScope
     *        wraps each of the two renders of a verified page: makes $request the request being handled
     *        and starts from the same request-scoped state (assets) both times
     * @param (\Closure(): bool)|null $applyPending applies pending invalidations, true when none is
     *        left (Refresher::applyPending()); null: a pending invalidation means no hit and no store
     * @param bool $rebuildOnly only rebuilds use the cache (the console)
     * @param (\Closure(): bool)|null $sessionActive whether a session is open, for rebuilds;
     *        session_status() === PHP_SESSION_ACTIVE when null
     */
    public function __construct(
        private readonly PageCacheConfig $config,
        private readonly \Closure $store,
        private readonly PageCache $cache,
        private readonly DependencyRecorder $recorder,
        private readonly PerRequestValues $values,
        private readonly EnabledMarker $marker,
        private readonly \Closure $clearAll,
        private readonly ?\Closure $localePrefix = null,
        private readonly ?\Closure $signedIn = null,
        private readonly ?\Closure $sessionStarted = null,
        private readonly ?\Closure $nonPublic = null,
        private readonly ?\Closure $onStale = null,
        private readonly ?\Closure $log = null,
        ?\Closure $clock = null,
        private readonly ?bool $sqlite = null,
        ?\Closure $sentHeaders = null,
        private readonly ?LiveParts $live = null,
        bool|string|null $verify = null,
        private readonly ?\Closure $visitorScope = null,
        private readonly ?\Closure $applyPending = null,
        private readonly bool $rebuildOnly = false,
        private readonly ?\Closure $sessionActive = null,
    ) {
        $this->sentHeaders = $sentHeaders ?? static fn (): array => headers_sent() ? [] : headers_list();
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->verify = match (true) {
            $verify === null => $config->verify,
            $verify === true => PageCacheConfig::VERIFY_ALWAYS,
            $verify === false => PageCacheConfig::VERIFY_OFF,
            default => (new PageCacheConfig(verify: $verify))->verify,
        };
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $rebuild = $request->getAttribute(self::REBUILD) === true;
        if (!$rebuild && ($this->rebuildOnly || InternalRender::is($request))) {
            return $handler->handle($request);
        }
        $key = $this->key($request);
        if ($key === null || ($this->nonPublic !== null && ($this->nonPublic)($request->getUri()->getPath()))) {
            return $handler->handle($request);
        }
        $expected = $rebuild ? $request->getAttribute(Rebuild::EXPECTED_ID) : null;
        if (is_string($expected) && $expected !== $key->id()) {
            // A key part the rebuild request can't carry: rendering it would store under another key.
            $this->log('page cache: the rebuild of ' . $key->url() . ' does not give the key it was stored under; it is built on its next visit');

            return new Response(409, [self::HEADER => 'rebuild-key']);
        }
        if ($this->carriesSignIn($request)) {
            return $handler->handle($request)->withHeader(self::HEADER, 'bypass');
        }

        try {
            $this->ensureEnabled();
            $store = ($this->store)();
            $page = $rebuild ? null : $store->get($key);
            if ($page !== null && PendingInvalidations::exist($store->releaseDir())) {
                // An invalidation that could not reach the index may concern this page: once it
                // is applied the page is read again, else it is not served.
                $page = $this->nothingPending($store) ? $store->get($key) : null;
            }
        } catch (\Throwable $e) {
            $this->log('page cache: ' . $e->getMessage());

            return $handler->handle($request);
        }

        if ($page !== null) {
            try {
                return $this->serve($key, $page);
            } catch (\Throwable $e) {
                // A per-request value or carried state that fails: render the page as on a miss.
                $this->log('page cache: ' . $key->url() . ' could not be served: ' . $e->getMessage());
            }
        }

        return $this->render($request, $handler, $key, $store);
    }

    /**
     * The key of $request: the URL as the visitor asked for it (with the locale prefix the
     * locale middleware stripped), the resolved locale, and the forwarded scheme and host.
     */
    private function key(ServerRequestInterface $request): ?PageKey
    {
        $parts = [];
        $locale = $request->getAttribute('locale');
        if (is_string($locale) && $locale !== '') {
            $parts['locale'] = $locale;
        }
        $proto = $request->getHeaderLine('X-Forwarded-Proto');
        if ($proto !== '') {
            $proto = strtolower(trim(explode(',', $proto)[0]));
            if ($proto !== 'http' && $proto !== 'https') {
                return null;
            }
            $parts['proto'] = $proto;
        }
        $host = $request->getHeaderLine('X-Forwarded-Host');
        if ($host !== '') {
            $host = strtolower(trim(explode(',', $host)[0]));
            if (!$this->config->cachesHost((string) preg_replace('/:\d+$/', '', $host))) {
                return null;
            }
            $parts['host'] = $host;
        }

        $prefix = $this->localePrefix !== null ? ($this->localePrefix)() : '';
        if ($prefix !== '') {
            $uri = $request->getUri();
            $path = $uri->getPath();
            $request = $request->withUri($uri->withPath($prefix . ($path === '/' || $path === '' ? '' : $path)), true);
        }

        return PageKey::fromRequest($request, $this->config, $parts);
    }

    private function carriesSignIn(ServerRequestInterface $request): bool
    {
        if ($request->getHeaderLine('Authorization') !== '') {
            return true;
        }
        $cookies = $request->getCookieParams();
        if ($cookies === []) {
            return false;
        }
        foreach ($this->config->signedInCookies as $name) {
            if (isset($cookies[$name]) && $cookies[$name] !== '') {
                return true;
            }
        }

        return false;
    }

    /** The first time in this process: a cache switched on again starts empty (EnabledMarker). */
    private function ensureEnabled(): void
    {
        if ($this->checked) {
            return;
        }
        $this->checked = true;
        if ($this->marker->exists()) {
            return;
        }
        ($this->clearAll)();
        if (!$this->marker->write()) {
            $this->log('page cache: the enabled marker could not be written; the cache is cleared on every request until it can.');
        }
    }

    /** Whether no invalidation is pending in $store's release, after trying to apply one. */
    private function nothingPending(PageStore $store): bool
    {
        if (!PendingInvalidations::exist($store->releaseDir())) {
            return true;
        }
        try {
            return $this->applyPending !== null && ($this->applyPending)();
        } catch (\Throwable $e) {
            $this->log('page cache: ' . $e->getMessage());

            return false;
        }
    }

    private function serve(PageKey $key, StoredPage $page): ResponseInterface
    {
        $stale = $page->staleAt(($this->clock)(), $this->config->maxAge);

        // Not for HEAD (uptime monitors): it changes nothing, and would use up the stale serve a GET gets.
        if ($stale && !$key->isHead()) {
            try {
                if ($this->onStale !== null) {
                    ($this->onStale)($key);
                } else {
                    // Nothing rebuilds it behind this visitor: served this once, the next renders it.
                    // Its rows go with its file (the index never knows a page that isn't stored).
                    $store = ($this->store)();
                    try {
                        $this->index ??= TagIndex::forStore($store, $this->config, $this->clock, $this->sqlite);
                        $this->index->forget($key->id());
                    } finally {
                        $store->forget($key->id());
                    }
                }
            } catch (\Throwable $e) {
                $this->log('page cache: ' . $e->getMessage());
            }
        }

        $headers = $page->headers;
        $headers[self::HEADER] = [$stale ? 'stale' : 'hit'];
        $this->cache->restoreState($page->state);
        $body = $this->values->fill($page->body);
        if ($this->live !== null && $this->isHtml($headers, $key)) {
            $body = $this->live->fill($body);
        }

        return new Response(200, $headers, $body);
    }

    private function render(ServerRequestInterface $request, RequestHandlerInterface $handler, PageKey $key, PageStore $store): ResponseInterface
    {
        $started = ($this->clock)();
        $frame = $this->recorder->start($started);
        $before = $this->cache->begin($key->isHead() ? null : $key);
        $carried = [];
        $reason = null;
        $verify = !$key->isHead() && $this->verifies($request, $key, $store);
        try {
            // Isolated (PageCache::isolate()): from the state a public render starts from, whatever
            // an earlier request in this process (an admin one, before its rebuilds) switched on.
            $response = $this->cache->isolated(fn (): ResponseInterface => $verify && $this->visitorScope !== null
                ? ($this->visitorScope)($request, static fn (): ResponseInterface => $handler->handle($request))
                : $handler->handle($request));
            if (!$key->isHead()) {
                $refusal = $this->cache->refusal();
                $reason = $refusal !== null ? 'refused:' . $refusal : $this->refusal($request, $key, $response);
                if ($reason === null) {
                    // Inside the recording: what the carried state reads (the site's SEO defaults)
                    // is a dependency of the page too.
                    try {
                        $carried = $this->cache->captureState();
                    } catch (\Throwable $e) {
                        $this->log('page cache: ' . $key->url() . ' was not stored: ' . $e->getMessage());
                        $reason = 'error';
                    }
                }
            }
        } finally {
            $expiry = $this->recorder->expiry($frame);
            $pastExpiry = $this->recorder->ignoredExpiry($frame);
            $tags = $this->recorder->stop($frame);
            $this->cache->end($before);
        }

        $response = $response->withHeader(self::HEADER, 'miss');
        if ($key->isHead()) {
            return $response; // never stored: GET stores the page HEAD is served from
        }

        $content = null;
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $content = (string) $body;
            $body->rewind();
        }
        // Live parts are filled into HTML only: elsewhere (a JSON string) a placeholder must not stay
        // stored, nor HTML be put in its place.
        $html = $this->isHtml($response->getHeaders(), $key, $content !== null && LivePlaceholder::in($content));
        if ($content !== null && !$html && LivePlaceholder::in($content)) {
            $reason ??= 'live-non-html';
        }
        if ($reason === null && $content !== null && $verify) {
            $differs = $this->differs($request, $handler, $key, $response, $content);
            // What the second render left (SEO's page meta) is put back to the first one's.
            $this->cache->restoreState($carried);
            if ($differs) {
                $this->log('page cache: ' . $key->url() . ' differs per visitor outside live parts');
                $reason = 'differs';
            }
        }
        if ($reason === null && $content !== null) {
            if ($pastExpiry !== null) {
                $this->logPastExpiry($key, $pastExpiry);
            }
            $reason = $this->store($key, $response, $content, $tags, $started, $store, $carried, $expiry);
            if ($reason === null && $verify && $this->verify === PageCacheConfig::VERIFY_FIRST) {
                try {
                    $this->index?->markVerified($key->id());
                } catch (\Throwable $e) {
                    $this->log('page cache: ' . $key->url() . ' could not be noted as verified: ' . $e->getMessage());
                }
            }
        }

        // The visitor's own live parts, whether the page was stored or not.
        if ($content !== null && $html && $this->live !== null && $this->live->in($content)) {
            $response = $response->withBody(\Nyholm\Psr7\Stream::create($this->live->fill($content)));
        }

        // A rebuild's response goes to Refresher::work() only, which retries one a write refused.
        $explain = $this->config->explain || $request->getAttribute(self::REBUILD) === true;

        return $reason === null || !$explain ? $response : $response->withHeader(self::SKIP_HEADER, $reason);
    }

    private bool $untypedLogged = false;

    /**
     * Whether a response is HTML (live parts are filled into nothing else). One without a
     * Content-Type is (PHP sends text/html for it); with $live (it holds live parts) that is
     * logged, once per process.
     *
     * @param array<string, list<string>> $headers
     */
    private function isHtml(array $headers, PageKey $key, bool $live = false): bool
    {
        foreach ($headers as $name => $values) {
            if (strcasecmp((string) $name, 'Content-Type') === 0) {
                return str_starts_with(strtolower(trim((string) ($values[0] ?? ''))), 'text/html');
            }
        }
        if ($live && !$this->untypedLogged) {
            $this->untypedLogged = true;
            $this->log('page cache: ' . $key->url() . ' has no Content-Type; it is treated as text/html');
        }

        return true;
    }

    /**
     * Whether a miss of $key is verified before it is stored: always, never, or ('first') when
     * the key was not verified yet in this release (TagIndex::verified(): kept when the page is
     * forgotten, so a page whose file or rows went is not verified again, and one stored without
     * verify is verified on its next miss; cache:clear and a new release verify anew).
     */
    private function verifies(ServerRequestInterface $request, PageKey $key, PageStore $store): bool
    {
        if ($this->verify !== PageCacheConfig::VERIFY_FIRST) {
            return $this->verify === PageCacheConfig::VERIFY_ALWAYS;
        }
        try {
            $index = $this->index ??= TagIndex::forStore($store, $this->config, $this->clock, $this->sqlite);

            return $index->available() && !$index->verified($key->id());
        } catch (\Throwable) {
            return false; // the store says why it can't store
        }
    }

    /**
     * Whether the page differs for another anonymous visitor: rendered once more (another
     * REMOTE_ADDR and forwarded address, no cookies) with nothing recorded, then compared with
     * $first (its stored headers) and $content (its body), with the per-request values and live
     * parts as placeholders, as they are stored. A second render that would not be stored either
     * (a cookie set for a visitor without one) or that fails differs too.
     */
    private function differs(ServerRequestInterface $request, RequestHandlerInterface $handler, PageKey $key, ResponseInterface $first, string $content): bool
    {
        $other = self::otherVisitor($request);
        $before = $this->cache->begin($key);
        $compared = null;
        try {
            // Its per-request values are its own (a token from its address): replaced inside its scope.
            $render = function () use ($handler, $other, &$compared): ResponseInterface {
                $response = $handler->handle($other);
                $body = $response->getBody();
                if ($body->isSeekable()) {
                    $compared = [$this->values->toPlaceholders((string) $body), $this->comparedHeaders($response)];
                    $body->rewind();
                }

                return $response;
            };
            if (session_status() === PHP_SESSION_ACTIVE) {
                // A cacheable render never has a session; one that does can't be verified.
                $this->log('page cache: ' . $key->url() . ' could not be verified: a session is open');

                return true;
            }
            $response = AnonymousGlobals::run((string) ($other->getServerParams()['REMOTE_ADDR'] ?? '192.0.2.1'), fn (): ResponseInterface => $this->recorder->outside(fn (): ResponseInterface => $this->cache->isolated(fn (): ResponseInterface => $this->visitorScope !== null
                ? ($this->visitorScope)($other, $render)
                : $render())));
            if ($this->cache->refusal() !== null || $this->refusal($other, $key, $response) !== null) {
                return true;
            }
        } catch (\Throwable $e) {
            $this->log('page cache: ' . $key->url() . ' could not be verified: ' . $e->getMessage());

            return true;
        } finally {
            $this->cache->end($before);
        }

        return $compared === null
            || $compared[0] !== $this->values->toPlaceholders($content)
            || $compared[1] !== $this->comparedHeaders($first);
    }

    /**
     * The headers a page is stored with, as verify compares them: names lowercased and sorted,
     * per-request values as placeholders.
     *
     * @return array<string, list<string>>
     */
    private function comparedHeaders(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($this->storedHeaders($response) as $name => $values) {
            $headers[strtolower($name)] = array_map($this->values->toPlaceholders(...), $values);
        }
        ksort($headers);

        return $headers;
    }

    /** $request as another anonymous visitor: another address (forwarded ones too), no cookies. */
    private static function otherVisitor(ServerRequestInterface $request): ServerRequestInterface
    {
        $server = $request->getServerParams();
        $address = ($server['REMOTE_ADDR'] ?? null) === '192.0.2.1' ? '192.0.2.2' : '192.0.2.1';
        $server['REMOTE_ADDR'] = $address;
        unset($server['HTTP_COOKIE']);
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_FORWARDED'] as $name) {
            if (isset($server[$name])) {
                $server[$name] = $name === 'HTTP_FORWARDED' ? 'for=' . $address : $address;
            }
        }
        $other = (new \Nyholm\Psr7\ServerRequest(
            $request->getMethod(),
            $request->getUri(),
            $request->getHeaders(),
            $request->getBody(),
            $request->getProtocolVersion(),
            $server,
        ))
            ->withoutHeader('Cookie')
            ->withQueryParams($request->getQueryParams())
            ->withParsedBody($request->getParsedBody())
            ->withUploadedFiles($request->getUploadedFiles());
        foreach (['X-Forwarded-For', 'X-Real-IP', 'Client-IP'] as $name) {
            if ($other->hasHeader($name)) {
                $other = $other->withHeader($name, $address);
            }
        }
        if ($other->hasHeader('Forwarded')) {
            $other = $other->withHeader('Forwarded', 'for=' . $address);
        }
        foreach ($request->getAttributes() as $name => $value) {
            $other = $other->withAttribute((string) $name, $value);
        }

        return $other;
    }

    /** Why $response must not be stored, null when it may be. */
    private function refusal(ServerRequestInterface $request, PageKey $key, ResponseInterface $response): ?string
    {
        if ($response->getStatusCode() !== 200) {
            return 'status';
        }
        // Only a render of the kind the key stands for: a history restore (HX-Request with the
        // full page's key) may be answered with a fragment by an app that branches on HX-Request.
        if ($key->isFragment() !== $request->hasHeader('HX-Request')) {
            return $request->hasHeader('HX-History-Restore-Request') ? 'restore' : 'kind';
        }
        if (!$this->values->signed()) {
            return 'app-key';
        }
        if (!$response->getBody()->isSeekable()) {
            return 'stream';
        }
        if ($response->hasHeader('Set-Cookie') || $this->nativeCookie()) {
            return 'set-cookie';
        }
        if ($request->getAttribute(self::REBUILD) === true) {
            // The request that queued it may have had a session (closed since): only one open now counts.
            if ($this->sessionActive !== null ? ($this->sessionActive)() : session_status() === PHP_SESSION_ACTIVE) {
                return 'session';
            }
        } elseif ($this->sessionStarted !== null && ($this->sessionStarted)()) {
            return 'session';
        }
        if ($this->signedIn !== null && ($this->signedIn)()) {
            return 'signed-in';
        }
        $control = strtolower($response->getHeaderLine('Cache-Control'));
        if ($control !== '' && preg_match('/(^|[\s,])(private|no-store)([\s,=]|$)/', $control) === 1) {
            return 'cache-control';
        }
        $vary = $response->getHeaderLine('Vary');
        if ($vary !== '') {
            $allowed = [...self::VARY_ALLOWED, ...array_map(strtolower(...), $this->config->varyHeaders)];
            foreach (explode(',', strtolower($vary)) as $name) {
                $name = trim($name);
                if ($name !== '' && !in_array($name, $allowed, true)) {
                    return 'vary';
                }
            }
        }
        $type = strtolower($response->getHeaderLine('Content-Type'));
        if ($type !== '' && !str_starts_with($type, 'text/') && !str_contains($type, 'json')
            && !str_contains($type, 'xml') && !str_contains($type, 'javascript')) {
            return 'content-type';
        }
        $size = $response->getBody()->getSize();
        if ($size !== null && $size > self::MAX_BODY) {
            return 'size';
        }

        return null;
    }

    /**
     * Stores the page: index rows first, then the file, then the check that nothing it read was
     * invalidated since the render started (a write by another request meanwhile) and that its
     * rows are still there (an invalidation that began earlier may have taken them). If a tag it
     * read fresh was, both go again; if only tags it read next-visit were, it stays, marked
     * stale (as the write would have marked it); tags it read timed don't count.
     *
     * Its expiresAt is the earliest of what the render asked for (PageCache::expiresAt(), the
     * expires tag) and, for each tag read timed, the render start plus its time. An expiry at or
     * before the render start is ignored (logged once per URL with explain on).
     *
     * Returns why it was not stored, null when it was.
     *
     * @param string $content the body as rendered (per-request values are replaced here)
     * @param array<string, array{0: Freshness, 1: ?int}> $tags
     * @param array<string, mixed> $carried PageCache::captureState()
     * @param float|null $expiry DependencyRecorder::expiry()
     */
    private function store(PageKey $key, ResponseInterface $response, string $content, array $tags, float $started, PageStore $store, array $carried, ?float $expiry = null): ?string
    {
        $fresh = [];
        $nextVisit = [];
        foreach ($tags as $tag => [$freshness, $seconds]) {
            match ($freshness) {
                Freshness::Fresh => $fresh[] = (string) $tag,
                Freshness::NextVisit => $nextVisit[] = (string) $tag,
                Freshness::Timed => $expiry = min($expiry ?? INF, $started + (int) $seconds),
            };
        }
        // An expiry at or before the render start says nothing (the recorder already left those out):
        // the page shows what came after it. Kept, it would make every copy stale for good.
        if ($expiry !== null && $expiry <= $started) {
            $this->logPastExpiry($key, $expiry);
            $expiry = null;
        }

        if (strlen($content) > self::MAX_BODY) {
            return 'size';
        }

        try {
            $index = $this->index ??= TagIndex::forStore($store, $this->config, $this->clock, $this->sqlite);
            if (!$index->available()) {
                return 'index';
            }
            if (!$this->nothingPending($store)) {
                return 'pending';
            }
            $page = new StoredPage(
                $this->values->toPlaceholders($content),
                $this->storedHeaders($response),
                $started,
                $expiry,
                state: $carried,
            );
            $index->record($key->id(), $key->url(), $key->isFragment(), $key->hasQuery(), $tags, $key->parts());
            if (!$store->put($key, $page)) {
                $index->forget($key->id());

                return 'head';
            }
            // Always asked (with no fresh tag too): an invalidation of everything counts for every page.
            // Rows gone meanwhile (an invalidation that began before this render): the file goes too,
            // the index could never find it.
            if ($index->invalidatedSince($fresh, $started) || $index->page($key->id()) === null) {
                $index->forget($key->id());
                $store->forget($key->id());

                return 'invalidated';
            }
            if ($nextVisit !== [] && $index->invalidatedSince($nextVisit, $started)) {
                $store->markStale($key->id());
            }
        } catch (\Throwable $e) {
            $this->log('page cache: ' . $key->url() . ' was not stored: ' . $e->getMessage());
            try {
                $this->index?->forget($key->id());
                $store->forget($key->id());
            } catch (\Throwable) {
            }

            return 'error';
        }

        return null;
    }

    /** With explain on, once per URL: an expiry that lay before the render was ignored. */
    private function logPastExpiry(PageKey $key, float $at): void
    {
        if (!$this->config->explain || isset($this->pastExpiries[$key->url()])) {
            return;
        }
        $this->pastExpiries[$key->url()] = true;
        $this->log('page cache: ' . $key->url() . ' asked to expire at ' . gmdate('Y-m-d\\TH:i:s\\Z', (int) $at) . ', before it rendered; ignored');
    }

    /** @return array<string, list<string>> */
    private function storedHeaders(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            if (!in_array(strtolower((string) $name), self::UNSTORED_HEADERS, true)) {
                $headers[(string) $name] = array_values($values);
            }
        }

        return $headers;
    }

    /** A cookie set with header()/setcookie() (a native session), which the PSR response doesn't show. */
    private function nativeCookie(): bool
    {
        foreach (($this->sentHeaders)() as $line) {
            if (strncasecmp($line, 'Set-Cookie:', 11) === 0) {
                return true;
            }
        }

        return false;
    }

    private function log(string $message): void
    {
        $this->log !== null ? ($this->log)($message) : error_log($message);
    }
}
