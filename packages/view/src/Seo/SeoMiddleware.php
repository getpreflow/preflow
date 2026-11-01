<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Preflow\View\NonceGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves `/robots.txt`, `/sitemap.xml` and `/.well-known/security.txt`
 * (`/security.txt` redirects there), adds robots, Open Graph, Twitter card,
 * theme-color and JSON-LD tags to HTML pages from the SeoContext a page
 * filled while rendering, and answers repeat visits with a 304 when a page
 * is the same for everyone (weak ETag over the body without the CSP nonce).
 * Runs just inside GEO, so GEO's internal renders get tags too.
 */
final class SeoMiddleware implements MiddlewareInterface
{
    /** The files are the same for every visitor, so a CDN or browser may keep them an hour. */
    private const CACHE_CONTROL = 'public, max-age=3600';

    /** @var \Closure(): list<string> */
    private readonly \Closure $sentHeaders;

    /**
     * @param ?string      $appUrl        the configured app.url; its origin makes URLs absolute (else the request's)
     * @param list<string> $locales       the locales with their own URL prefix `/{locale}/` (the default has none)
     * @param string       $appName       the site name when neither the config nor SeoSite give one
     * @param bool         $llms          whether llms.txt is served (GEO on), for robots.txt's pointer
     * @param \Closure(): \DateTimeImmutable $now security.txt's Expires counts from it
     * @param ?string      $sessionCookie the session cookie's name (config); session_name() when null
     * @param ?\Closure(): list<string> $sentHeaders headers PHP queued natively; headers_list() by default
     */
    public function __construct(
        private readonly SeoConfig $config,
        private readonly SeoContext $context,
        private readonly PublicPages $pages,
        private readonly SchemaRegistry $schemas,
        private readonly SeoSite $site,
        private readonly NonceGenerator $nonce,
        private readonly ?string $appUrl,
        private readonly array $locales,
        private readonly string $defaultLocale,
        private readonly string $appName,
        private readonly bool $llms,
        private readonly \Closure $now,
        private readonly ?string $sessionCookie = null,
        ?\Closure $sentHeaders = null,
    ) {
        $this->sentHeaders = $sentHeaders ?? static fn (): array => headers_list();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->config->enabled()) {
            return $handler->handle($request);
        }

        $method = $request->getMethod();
        if ($method === 'GET' || $method === 'HEAD') {
            $file = $this->file($request);
            if ($file !== null) {
                return $file;
            }
        }

        $this->context->reset();
        $response = $handler->handle($request);
        if ($response->getStatusCode() !== 200 || !self::isHtml($response)) {
            return $response;
        }

        // Paths kept from crawlers (an admin) get no head tags.
        if (!$this->isDisallowed($request->getUri()->getPath())) {
            $response = $this->withHeadTags($request, $response);
        }

        return $method === 'GET' || $method === 'HEAD' ? $this->withEtag($request, $response) : $response;
    }

    /** robots.txt, sitemap.xml or security.txt when $request asks for one that is switched on, else null. */
    private function file(ServerRequestInterface $request): ?ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $contact = $this->config->securityContact;

        return match (true) {
            $path === '/robots.txt' && $this->config->robotsEnabled => self::text(
                'text/plain; charset=utf-8',
                RobotsTxt::render($this->config, $this->site->disallowed(), PageSignals::origin($this->appUrl, $request), $this->llms, $this->site->allowed()),
            ),
            $path === '/sitemap.xml' && $this->config->sitemap => self::text(
                'application/xml; charset=UTF-8',
                Sitemap::xml($this->sitemapPages(), PageSignals::origin($this->appUrl, $request), $this->defaultLocale),
            ),
            $path === '/.well-known/security.txt' && $contact !== null => self::text(
                'text/plain; charset=utf-8',
                SecurityTxt::render($contact, $this->config->securityPolicy, $this->locales, PageSignals::origin($this->appUrl, $request), ($this->now)()),
            ),
            $path === '/security.txt' && $contact !== null => new Response(301, [
                'Location' => PageSignals::origin($this->appUrl, $request) . '/.well-known/security.txt',
                'Cache-Control' => self::CACHE_CONTROL,
            ]),
            default => null,
        };
    }

    /** @return array<string, list<PublicPage>> */
    private function sitemapPages(): array
    {
        $pages = [];
        foreach ($this->locales === [] ? [$this->defaultLocale] : $this->locales as $locale) {
            $pages[$locale] = $this->pages->sitemap($locale);
        }

        return $pages;
    }

    private static function text(string $type, string $body): ResponseInterface
    {
        return new Response(200, [
            'Content-Type' => $type,
            'Cache-Control' => self::CACHE_CONTROL,
            'X-Content-Type-Options' => 'nosniff',
        ], $body);
    }

    private function withHeadTags(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $html = (string) $response->getBody();
        if (stripos($html, '</head>') === false) {
            return $response;
        }

        $origin = PageSignals::origin($this->appUrl, $request);
        $path = $request->getUri()->getPath();
        $locale = $this->localeOf($path);
        $defaults = $this->site->defaultsFor($locale);
        $page = $this->context->get() ?? new PageMeta();
        // From the site only what describes the site: description and share image. Its title is the site's name.
        $description = $page->description !== '' ? $page->description : ($defaults?->description ?? '');
        $image = $page->image ?? $defaults?->image;
        $meta = $page->with([
            'title' => $page->title !== '' ? $page->title : self::documentTitle($html),
            'description' => $description,
            'url' => $page->url !== '' ? PageSignals::absolute($page->url, $origin) : $origin . $path,
            'locale' => $page->locale !== '' ? $page->locale : $locale,
            'image' => $image !== null && ($image['url'] ?? '') !== ''
                ? ['url' => PageSignals::absolute($image['url'], $origin)] + $image
                : null,
            // A page kept out of search by its own tag or by header gets no cards either.
            'noindex' => $page->noindex || PageSignals::noindexHeader($response) || PageSignals::noindexMeta($html),
        ]);
        // A private page (a members' area, a gated page) isn't previewed or described to crawlers.
        $cards = !self::isPrivate($response);

        $siteName = $this->config->name ?? (($defaults?->title ?? '') !== '' ? $defaults->title : $this->appName);
        $home = $path === $this->homePath($locale) || $path === $this->homePath($locale) . '/';
        $graph = $meta->noindex || !$cards ? [] : $this->graph($meta, $siteName, $origin, $home);

        $tagged = HeadTags::inject($html, $meta, $siteName, $this->config->themeColor, $graph, $cards);
        if ($tagged === $html) {
            return $response;
        }

        return $response->withoutHeader('Content-Length')->withBody(Stream::create($tagged));
    }

    /** The text of the page's own `<title>`, or '' when it has none. */
    private static function documentTitle(string $html): string
    {
        $head = substr($html, 0, (int) stripos($html, '</head>'));
        if (preg_match('~<title\b[^>]*>(.*?)</title>~is', $head, $m) !== 1) {
            return '';
        }
        $text = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('~[\s\x{00A0}]+~u', ' ', $text) ?? $text);
    }

    /**
     * The JSON-LD graph: the site and its owner on a locale's home page, the
     * page itself and the nodes it added everywhere.
     *
     * @return list<array>
     */
    private function graph(PageMeta $meta, string $siteName, string $origin, bool $home): array
    {
        $graph = [];
        if ($home) {
            $owner = $this->schemas->ownerFor($meta->locale, $this->config->owner);
            $ownerNode = $owner !== null && trim((string) ($owner['name'] ?? '')) !== '' ? Schema::owner($owner, $origin) : null;
            $graph[] = Schema::webSite($origin, $siteName, $meta->locale, $ownerNode['@id'] ?? null);
            if ($ownerNode !== null) {
                $graph[] = $ownerNode;
            }
        }

        $graph[] = Schema::webPage($meta->url, $meta->title, HeadTags::description($meta->description), $meta->locale, $origin, $meta->image);

        return [...$graph, ...$meta->schemas];
    }

    /**
     * A weak ETag for a page that is the same for every visitor, and a 304
     * when the client has it. Never for a request with the session cookie or
     * a response that sets a cookie or is private.
     */
    private function withEtag(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->config->etag || $this->isPersonal($request, $response)) {
            return $response;
        }

        // The nonce changes on every request; the page doesn't.
        $nonce = $this->nonce->get();
        $etag = 'W/"' . sha1(str_replace($nonce, '', (string) $response->getBody())) . '"';

        $response = $response->withHeader('ETag', $etag);
        if (!$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', 'no-cache');
        }

        if (!self::matches($request->getHeaderLine('If-None-Match'), $etag)) {
            return $response;
        }

        return $response
            ->withStatus(304)
            ->withoutHeader('Content-Length')
            ->withBody(Stream::create(''));
    }

    private function isPersonal(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        if (self::isPrivate($response) || $response->hasHeader('Set-Cookie')) {
            return true;
        }
        foreach (($this->sentHeaders)() as $header) {
            if (stripos($header, 'set-cookie:') === 0) {
                return true;
            }
        }

        $session = $this->sessionCookie ?? session_name();

        return $session !== false && $session !== '' && self::hasCookie($request, $session);
    }

    /** Whether the response is meant for one visitor only (`Cache-Control: private` or `no-store`). */
    private static function isPrivate(ResponseInterface $response): bool
    {
        $cacheControl = strtolower($response->getHeaderLine('Cache-Control'));

        return str_contains($cacheControl, 'private') || str_contains($cacheControl, 'no-store');
    }

    private static function hasCookie(ServerRequestInterface $request, string $name): bool
    {
        if (array_key_exists($name, $request->getCookieParams())) {
            return true;
        }
        foreach (explode(';', $request->getHeaderLine('Cookie')) as $pair) {
            if (trim(explode('=', $pair, 2)[0]) === $name) {
                return true;
            }
        }

        return false;
    }

    /** Whether an If-None-Match value matches $etag (weak comparison, a list or `*`). */
    private static function matches(string $ifNoneMatch, string $etag): bool
    {
        if (trim($ifNoneMatch) === '') {
            return false;
        }
        if (trim($ifNoneMatch) === '*') {
            return true;
        }

        $opaque = static fn (string $tag): string => preg_replace('~^W/~i', '', trim($tag)) ?? '';
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            if ($opaque($candidate) === $opaque($etag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $path is under one of the paths a site keeps from crawlers (SeoSite::disallow())
     * and not under one of their exceptions (SeoSite::allow()).
     */
    private function isDisallowed(string $path): bool
    {
        foreach ($this->site->allowed() as $rule) {
            if (self::underRule($path, $rule)) {
                return false;
            }
        }
        foreach ($this->site->disallowed() as $rule) {
            if (self::underRule($path, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a robots.txt rule covers $path, read as robots.txt reads it: a prefix, `*` for any
     * characters, a closing `$` for the end. A path that is the rule's directory without its slash
     * ("/folio" for "/folio/") counts too.
     */
    private static function underRule(string $path, string $rule): bool
    {
        $end = str_ends_with($rule, '$');
        $pattern = '~^' . str_replace('\\*', '.*', preg_quote($end ? substr($rule, 0, -1) : $rule, '~')) . ($end ? '$' : '') . '~';

        return preg_match($pattern, $path) === 1 || (!$end && str_ends_with($rule, '/') && $path . '/' === $rule);
    }

    /** The locale a path is in: its prefix when that is one of the other locales, else the default. */
    private function localeOf(string $path): string
    {
        $prefix = explode('/', ltrim($path, '/'), 2)[0];

        return $prefix !== '' && $prefix !== $this->defaultLocale && in_array($prefix, $this->locales, true)
            ? $prefix
            : $this->defaultLocale;
    }

    /** `/` for the default locale, `/{locale}` for the others (a trailing slash is the same page). */
    private function homePath(string $locale): string
    {
        return $locale === $this->defaultLocale ? '' : '/' . $locale;
    }

    private static function isHtml(ResponseInterface $response): bool
    {
        return str_starts_with(strtolower(trim($response->getHeaderLine('Content-Type'))), 'text/html');
    }
}
