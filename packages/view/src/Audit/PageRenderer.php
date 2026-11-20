<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

use Nyholm\Psr7\ServerRequest;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Http\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders one of the site's own pages for the SEO audit, inside the app (no
 * network), as an anonymous visitor in production sees it: a clean GET with no
 * cookies, credentials, query or session, which the app renders as an internal
 * Audit render (Application::render(): it leaves no trace, gets no debug toolbar
 * or debug error page, and its data writes are skipped and reported on the page).
 * Works without an outer request (the console command). Never throws: an error
 * that escapes the app becomes a plain-text 500 page whose body is the error's
 * class and message.
 *
 * @since 0.54
 */
final class PageRenderer
{
    private readonly string $origin;

    /**
     * @param \Closure(ServerRequestInterface $request, string $locale): (RenderResult|ResponseInterface) $render
     *        renders the clean request of a page in its locale (the app: Application::render() in an
     *        internal Audit context, since 0.81); a plain response reports no skipped writes
     * @param string         $origin        scheme and host of the site (app.url); pages' URLs start with it
     * @param list<string>   $locales       locales with a URL prefix ('/de/…')
     * @param string         $defaultLocale the locale of paths without a prefix
     */
    public function __construct(
        private readonly \Closure $render,
        string $origin,
        private readonly array $locales = [],
        private readonly string $defaultLocale = '',
    ) {
        $this->origin = rtrim($origin !== '' ? $origin : 'http://localhost', '/');
    }

    /** The site's scheme and host, as pages' URLs start with it (no trailing slash). */
    public function origin(): string
    {
        return $this->origin;
    }

    /**
     * The site's locales, the default one included: those with a URL prefix, or
     * only the default when no locale has one.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->locales !== [] ? $this->locales : [$this->defaultLocale];
    }

    /** The locale of paths without a URL prefix. */
    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    /**
     * Render the page at $path ('/de/about'; a query or fragment is dropped). $headers are added to the
     * request (a conditional render's If-None-Match); Cookie and Authorization are left out, so the render
     * stays anonymous, and Accept stays text/html.
     *
     * @param array<string, string> $headers
     */
    public function render(string $path, array $headers = []): RenderedPage
    {
        $path = (string) parse_url('/' . ltrim($path, '/'), PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }
        $url = $this->origin . $path;
        $locale = $this->localeOf($path);

        try {
            $result = ($this->render)($this->request($url, $path, $headers), $locale);
            $response = $result instanceof RenderResult ? $result->response : $result;
            $writes = $result instanceof RenderResult ? $result->skippedWrites : [];

            return new RenderedPage($url, $locale, $response->getStatusCode(), $response->getHeaders(), (string) $response->getBody(), $writes);
        } catch (\Throwable $e) {
            return new RenderedPage($url, $locale, 500, ['Content-Type' => ['text/plain; charset=UTF-8']], $e::class . ': ' . $e->getMessage());
        }
    }

    /**
     * A clean GET for $url: no cookies, credentials, query or attributes, and no session.
     *
     * @param array<string, string> $headers
     */
    private function request(string $url, string $path, array $headers): ServerRequestInterface
    {
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);

        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'QUERY_STRING' => '',
            'SERVER_NAME' => $host,
            'SERVER_PORT' => (string) $port,
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        if ($scheme === 'https') {
            $server['HTTPS'] = 'on';
        }

        $headers = array_filter(
            $headers,
            static fn (string $name): bool => !in_array(strtolower($name), ['cookie', 'authorization', 'accept'], true),
            ARRAY_FILTER_USE_KEY,
        );
        return (new ServerRequest('GET', $url, ['Accept' => 'text/html'] + $headers, null, '1.1', $server))
            ->withAttribute(SessionMiddleware::WITHOUT_SESSION, true);
    }

    /** The locale whose URL prefix $path starts with, else the default. */
    private function localeOf(string $path): string
    {
        $first = explode('/', ltrim($path, '/'), 2)[0];

        return $first !== '' && in_array($first, $this->locales, true) ? $first : $this->defaultLocale;
    }
}
