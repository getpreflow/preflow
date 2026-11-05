<?php

declare(strict_types=1);

namespace Preflow\View\Geo;

use Dom\HTMLDocument;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Preflow\Core\Exceptions\HttpException;
use Preflow\Core\Http\Session\SessionMiddleware;
use Preflow\View\AssetCollector;
use Preflow\View\Seo\PageSignals;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Markdown views of public pages: `/about.md`, `/index.md` (home), `/de/index.md`,
 * or a page URL asked for with `Accept: text/markdown`; and `/llms.txt` and
 * `/llms-full.txt` (`/de/llms.txt`… for the other locales) from GeoEntries.
 *
 * The page is rendered internally as an anonymous visitor would see it (no
 * cookies, no session, no query) and converted; for pages that aren't a plain
 * 200 HTML page, are noindex or are excluded, the `.md` URL answers 404 and an
 * `Accept: text/markdown` request gets the normal response. HTML pages get a
 * `rel="alternate"` link to their Markdown, in the head and as a `Link` header.
 * Runs outermost, so the internal render passes through session, auth and
 * locale middleware like a fresh request.
 */
final class GeoMiddleware implements MiddlewareInterface
{
    /** Request headers an internal render keeps; everything else (cookies, credentials, language) is dropped. */
    private const KEPT_HEADERS = ['X-Forwarded-Proto', 'X-Forwarded-Host', 'X-Forwarded-Port'];

    /** Markdown and llms files are the same for every visitor, so a CDN or browser may keep them an hour. */
    private const CACHE_CONTROL = 'public, max-age=3600';

    /**
     * @param ?string       $appUrl the configured app.url; its origin makes links absolute (else the request's)
     * @param ?RenderScopes  $scopes wrap each internal render (request-scoped state set and put back)
     * @param ?\Closure(\Throwable): void $report gets render failures that aren't HTTP answers (404, 403…)
     * @param bool           $debug  rethrow those failures, so the error page shows them
     * @param ?GeoEntries    $entries the pages llms.txt lists (none: no llms.txt)
     * @param string         $appName llms.txt's name when neither the config nor the site fields give one
     * @param list<string>   $locales the locales with their own llms.txt under `/{locale}/`
     * @param string         $defaultLocale the locale of `/llms.txt`
     * @param ?\Closure(): ?object $translator gives the app's translator (a Preflow\I18n\Translator), if any;
     *                       llms.txt's "Other languages" heading comes from its geo.other_languages
     *                       in the file's locale (none: English)
     */
    public function __construct(
        private readonly GeoConfig $config,
        private readonly GeoCollector $collector,
        private readonly MarkdownConverter $converter,
        private readonly AssetCollector $assets,
        private readonly ?string $appUrl = null,
        private readonly ?RenderScopes $scopes = null,
        private readonly ?\Closure $report = null,
        private readonly bool $debug = false,
        private readonly ?GeoEntries $entries = null,
        private readonly string $appName = '',
        private readonly array $locales = [],
        private readonly string $defaultLocale = '',
        private readonly ?\Closure $translator = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        if (
            !$this->config->enabled()
            || ($method !== 'GET' && $method !== 'HEAD')
            || str_starts_with($path, '/--component')
        ) {
            return $handler->handle($request);
        }

        $llms = $this->llmsFile($path);
        if ($llms !== null) {
            return $this->llmsResponse($request, $handler, ...$llms);
        }

        $page = self::pagePath($path);
        if ($page !== null) {
            return $this->markdownResponse($this->markdown($request, $handler, $page));
        }

        if ($this->prefersMarkdown($request)) {
            $markdown = $this->markdown($request, $handler, $path);
            if ($markdown !== null) {
                return $this->markdownResponse($markdown);
            }
            // No Markdown view (a redirect, JSON, a 404…): the response the client would get anyway.
            return self::varyAccept($this->linkAlternate($request, $handler->handle($request)));
        }

        return $this->linkAlternate($request, $handler->handle($request));
    }

    /**
     * The Markdown of one page path, or null when it has none (not 200 HTML,
     * noindex, an HTTP error). Rendered anonymously through $handler with its own
     * assets and geo blocks, so several renders in one request stay independent.
     * Other failures are reported and give null; in debug mode they are rethrown.
     */
    public function render(ServerRequestInterface $request, RequestHandlerInterface $handler, string $path): ?string
    {
        $origin = PageSignals::origin($this->appUrl, $request);
        $sub = $this->subRequest($request, $path);

        $this->collector->reset();
        $this->collector->capture(true);
        try {
            $response = $this->assets->isolate(fn (): ResponseInterface => $this->scoped($sub, $handler));

            return $this->markdownOf($response, $path, $origin);
        } catch (\Throwable $e) {
            if ($e instanceof HttpException && $e->statusCode < 500) {
                return null; // not found, forbidden…: the page has no public view
            }
            if ($this->debug) {
                throw $e;
            }
            $this->report?->__invoke($e);

            return null;
        } finally {
            $this->collector->capture(false);
            $this->collector->reset();
        }
    }

    /** The Markdown URL of a page path: `/about` → `/about.md`, `/` → `/index.md`, `/de/` → `/de/index.md`. */
    public static function markdownUrl(string $path): string
    {
        return str_ends_with($path, '/') ? $path . 'index.md' : $path . '.md';
    }

    /** The page path of a Markdown URL, or null when $path isn't one. */
    private static function pagePath(string $path): ?string
    {
        if (!str_ends_with($path, '.md')) {
            return null;
        }
        if (str_ends_with($path, '/index.md')) {
            return substr($path, 0, -strlen('index.md'));
        }

        $page = substr($path, 0, -3);

        return $page === '' || str_ends_with($page, '/') ? null : $page;
    }

    /** A page's Markdown, or null when it is excluded or has none. */
    private function markdown(ServerRequestInterface $request, RequestHandlerInterface $handler, string $page): ?string
    {
        return $this->config->excludes($page) ? null : $this->render($request, $handler, $page);
    }

    private function markdownResponse(?string $markdown): ResponseInterface
    {
        $this->dropNativeCookies();

        $headers = ['Vary' => 'Accept', 'X-Content-Type-Options' => 'nosniff'];

        return $markdown === null
            ? new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'] + $headers, "Not Found\n")
            : new Response(200, ['Content-Type' => 'text/markdown; charset=utf-8', 'Cache-Control' => self::CACHE_CONTROL] + $headers, $markdown);
    }

    /** $response with `Accept` in its `Vary` header. */
    private static function varyAccept(ResponseInterface $response): ResponseInterface
    {
        $vary = array_map('trim', explode(',', strtolower($response->getHeaderLine('Vary'))));

        return in_array('accept', $vary, true) ? $response : $response->withAddedHeader('Vary', 'Accept');
    }

    /**
     * The llms file a path names: `/llms.txt`, `/llms-full.txt`, or either under
     * `/{locale}/` for a non-default locale.
     *
     * @return ?array{string, bool} the locale, and whether it is the full file
     */
    private function llmsFile(string $path): ?array
    {
        if ($this->entries === null || preg_match('#^(?:/([^/]+))?/llms(-full)?\.txt$#', $path, $m) !== 1) {
            return null;
        }
        $prefix = $m[1] ?? '';
        $locale = $prefix === '' ? $this->defaultLocale : $prefix;
        if ($prefix !== '' && ($locale === $this->defaultLocale || !in_array($locale, $this->locales, true))) {
            return null;
        }

        return [$locale, ($m[2] ?? '') !== ''];
    }

    private function llmsResponse(ServerRequestInterface $request, RequestHandlerInterface $handler, string $locale, bool $full): ResponseInterface
    {
        $headers = ['Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff'];
        if ($full && !$this->config->full) {
            $this->dropNativeCookies();

            return new Response(404, $headers, "Not Found\n");
        }

        $origin = PageSignals::origin($this->appUrl, $request);
        $entries = [];
        foreach ($this->entries?->for($locale) ?? [] as $entry) {
            $entry = $entry->withUrl(PageSignals::absolute($entry->url, $origin));
            $path = $this->localPath($entry->url, $origin);
            if ($path === null || !$this->config->excludes($path)) {
                $entries[] = $entry;
            }
        }

        $site = $this->entries?->siteFields($locale) ?? [];
        $name = $this->config->name ?? $this->siteText($site, 'name') ?? $this->appName;
        $summary = $this->config->summary ?? $this->siteText($site, 'summary') ?? '';
        $txt = new LlmsTxt();

        if ($full) {
            $pages = [];
            foreach ($entries as $entry) {
                $path = $entry->full ? $this->localPath($entry->url, $origin) : null;
                $markdown = $path !== null ? $this->render($request, $handler, $path) : null;
                if ($markdown !== null) {
                    $pages[] = ['entry' => $entry, 'markdown' => self::withoutHeader($markdown)];
                }
            }
            $body = $txt->full($name, $summary, $pages);
        } else {
            $others = [];
            if ($locale === $this->defaultLocale) {
                foreach ($this->locales as $other) {
                    if ($other !== $this->defaultLocale) {
                        $others[] = ['locale' => $other, 'url' => $origin . '/' . $other . '/llms.txt'];
                    }
                }
            }
            $body = $others !== []
                ? $txt->index($name, $summary, $entries, $others, $this->otherLanguagesHeading($locale))
                : $txt->index($name, $summary, $entries, $others);
        }

        $this->dropNativeCookies();

        return new Response(200, ['Cache-Control' => self::CACHE_CONTROL] + $headers, $body);
    }

    /** The "Other languages" heading in $locale: the translator's geo.other_languages, else English. */
    private function otherLanguagesHeading(string $locale): string
    {
        $translator = $this->translator?->__invoke();
        if ($translator === null || !method_exists($translator, 'get')) {
            return 'Other languages';
        }
        $heading = trim((string) $translator->get(LlmsTxt::OTHER_LANGUAGES_KEY, [], $locale !== '' ? $locale : null));

        return $heading !== '' && $heading !== LlmsTxt::OTHER_LANGUAGES_KEY ? $heading : 'Other languages';
    }

    /** @param array<string, mixed> $site */
    private function siteText(array $site, string $key): ?string
    {
        $field = $this->config->siteFields[$key] ?? null;
        $value = $field !== null ? ($site[$field] ?? null) : null;
        $text = is_scalar($value) ? trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';

        return $text !== '' ? $text : null;
    }

    /** The path of a URL on this site (same origin), or null for another site's. */
    private function localPath(string $url, string $origin): ?string
    {
        if ($origin === '' || !str_starts_with($url, $origin) || !in_array($url[strlen($origin)] ?? '/', ['/', '?', '#'], true)) {
            return null;
        }
        $path = (string) parse_url(substr($url, strlen($origin)) ?: '/', PHP_URL_PATH);

        return $path !== '' ? $path : '/';
    }

    /** A page's Markdown without the title and `Source:` lines render() puts first. */
    private static function withoutHeader(string $markdown): string
    {
        return (string) preg_replace('/\A(?:# [^\n]*\n\n)?Source: [^\n]*\n\n?/', '', $markdown);
    }

    /** Anonymous and cacheable: no cookie leaves, not even one PHP's session queued natively. */
    private function dropNativeCookies(): void
    {
        if (!headers_sent()) {
            header_remove('Set-Cookie');
        }
    }

    /** A clean GET for $path: no cookies, credentials, query or attributes, and no session. */
    private function subRequest(ServerRequestInterface $request, string $path): ServerRequestInterface
    {
        $uri = $request->getUri()->withPath($path)->withQuery('')->withFragment('');

        $headers = ['Accept' => 'text/html'];
        foreach (self::KEPT_HEADERS as $name) {
            if ($request->hasHeader($name)) {
                $headers[$name] = $request->getHeader($name);
            }
        }

        $server = array_filter(
            $request->getServerParams(),
            static fn (string $key): bool => !str_starts_with($key, 'HTTP_')
                && !in_array($key, ['PHP_AUTH_USER', 'PHP_AUTH_PW', 'PHP_AUTH_DIGEST', 'AUTH_TYPE', 'REMOTE_USER'], true),
            ARRAY_FILTER_USE_KEY,
        );
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'QUERY_STRING' => ''] + $server;

        return (new ServerRequest('GET', $uri, $headers, null, $request->getProtocolVersion(), $server))
            ->withAttribute(SessionMiddleware::WITHOUT_SESSION, true);
    }

    /** Handle $sub inside every render scope. */
    private function scoped(ServerRequestInterface $sub, RequestHandlerInterface $handler): ResponseInterface
    {
        $render = static fn (): ResponseInterface => $handler->handle($sub);

        return $this->scopes?->run($sub, $render) ?? $render();
    }

    private function markdownOf(ResponseInterface $response, string $path, string $origin): ?string
    {
        if ($response->getStatusCode() !== 200 || !$this->isHtml($response) || PageSignals::noindexHeader($response)) {
            return null;
        }

        $html = (string) $response->getBody();
        if (PageSignals::noindexMeta($html)) {
            return null;
        }

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $title = trim((string) preg_replace('/\s+/', ' ', $document->querySelector('title')?->textContent ?? ''));
        $canonical = trim((string) $document->querySelector('link[rel~="canonical"][href]')?->getAttribute('href'));
        $source = $canonical !== '' ? PageSignals::absolute($canonical, $origin) : $origin . $path;

        // Blocks a page or layout template declares outside components lead the converted page
        // (a page that wants only its block marks its own HTML data-geo="skip").
        $parts = array_filter(
            [...$this->collector->pageBlocks(), $this->converter->convert($html, $origin . $path)],
            static fn (string $part): bool => $part !== '',
        );
        $body = implode("\n\n", $parts);

        $markdown = ($title !== '' ? '# ' . $title . "\n\n" : '') . 'Source: ' . $source . "\n";

        return $body === '' ? $markdown : $markdown . "\n" . $body . "\n";
    }

    /** Add the Markdown alternate to a public HTML page: `Link` header and `<link>` in the head. */
    private function linkAlternate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        // A 304 (SEO's ETag) sends the Vary its 200 would have (RFC 9110 15.4.5); its Link needs the
        // page's body to tell (a noindex meta tag), so the client keeps the one it has.
        if ($response->getStatusCode() === 304 && !$this->config->excludes($path)) {
            return self::varyAccept($response);
        }
        if (
            $response->getStatusCode() !== 200
            || !$this->isHtml($response)
            || $request->getHeaderLine('HX-Request') === 'true'
            || $this->config->excludes($path)
            || PageSignals::noindexHeader($response)
        ) {
            return $response;
        }

        $html = (string) $response->getBody();
        if (PageSignals::noindexMeta($html)) {
            return $response;
        }

        $url = PageSignals::origin($this->appUrl, $request) . self::markdownUrl($path);
        $response = self::varyAccept($response->withAddedHeader('Link', '<' . $url . '>; rel="alternate"; type="text/markdown"'));

        $headAt = stripos($html, '</head>');
        if ($headAt === false) {
            return $response;
        }
        $tag = '<link rel="alternate" type="text/markdown" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5) . '">';

        return $response
            ->withoutHeader('Content-Length')
            ->withBody(Stream::create(substr_replace($html, $tag, $headAt, 0)));
    }

    /** Whether the client ranks text/markdown above text/html (it must name text/markdown itself). */
    private function prefersMarkdown(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');
        if (stripos($accept, 'text/markdown') === false) {
            return false;
        }

        $ranges = [];
        foreach (explode(',', $accept) as $index => $part) {
            $params = explode(';', $part);
            $type = strtolower(trim((string) array_shift($params)));
            $q = 1.0;
            foreach ($params as $param) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $param, $m) === 1) {
                    $q = (float) $m[1];
                }
            }
            $ranges[] = ['type' => $type, 'q' => $q, 'index' => $index];
        }

        $markdown = $this->quality($ranges, ['text/markdown']);
        if ($markdown === null || $markdown['q'] <= 0.0) {
            return false;
        }
        $html = $this->quality($ranges, ['text/html', 'text/*', '*/*']);

        return $html === null
            || $markdown['q'] > $html['q']
            || ($markdown['q'] === $html['q'] && $markdown['index'] < $html['index']);
    }

    /**
     * The first range matching the most specific of $types.
     *
     * @param list<array{type: string, q: float, index: int}> $ranges
     * @param list<string> $types most specific first
     * @return ?array{type: string, q: float, index: int}
     */
    private function quality(array $ranges, array $types): ?array
    {
        foreach ($types as $type) {
            foreach ($ranges as $range) {
                if ($range['type'] === $type) {
                    return $range;
                }
            }
        }

        return null;
    }

    private function isHtml(ResponseInterface $response): bool
    {
        return str_starts_with(strtolower(trim($response->getHeaderLine('Content-Type'))), 'text/html');
    }
}
