<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

use Nyholm\Psr7\ServerRequest;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\Http\Session\SessionMiddleware;
use Preflow\View\AssetCollector;
use Preflow\View\Geo\RenderScopes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders one of the site's own pages for the SEO audit, inside the app (no
 * network), as an anonymous visitor in production sees it: a clean GET with no
 * cookies, credentials, query or session, marked as an internal render (so it
 * leaves no trace and gets no debug toolbar or debug error page), through the
 * app's full pipeline inside the render scopes. Works without an outer request
 * (the console command). Never throws: an error that escapes the app becomes a
 * plain-text 500 page whose body is the error's class and message.
 *
 * @since 0.54
 */
final class PageRenderer
{
    private readonly string $origin;

    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $handle the app (Application::handle)
     * @param string         $origin        scheme and host of the site (app.url); pages' URLs start with it
     * @param ?RenderScopes  $scopes        wrap each render (request-scoped state set and put back)
     * @param ?AssetCollector $assets       put back after each render, so renders don't share CSS/JS
     * @param list<string>   $locales       locales with a URL prefix ('/de/…')
     * @param string         $defaultLocale the locale of paths without a prefix
     */
    public function __construct(
        private readonly \Closure $handle,
        string $origin,
        private readonly ?RenderScopes $scopes = null,
        private readonly ?AssetCollector $assets = null,
        private readonly array $locales = [],
        private readonly string $defaultLocale = '',
    ) {
        $this->origin = rtrim($origin !== '' ? $origin : 'http://localhost', '/');
    }

    /** Render the page at $path ('/de/about'; a query or fragment is dropped). */
    public function render(string $path): RenderedPage
    {
        $path = (string) parse_url('/' . ltrim($path, '/'), PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }
        $url = $this->origin . $path;
        $locale = $this->localeOf($path);

        try {
            $request = $this->request($url, $path);
            $render = fn (): ResponseInterface => ($this->handle)($request);
            $scoped = fn (): ResponseInterface => $this->scopes?->run($request, $render) ?? $render();
            $response = $this->assets !== null ? $this->assets->restoring($scoped) : $scoped();

            return new RenderedPage($url, $locale, $response->getStatusCode(), $response->getHeaders(), (string) $response->getBody());
        } catch (\Throwable $e) {
            return new RenderedPage($url, $locale, 500, ['Content-Type' => ['text/plain; charset=UTF-8']], $e::class . ': ' . $e->getMessage());
        }
    }

    /** A clean, marked GET for $url: no cookies, credentials, query or attributes, and no session. */
    private function request(string $url, string $path): ServerRequestInterface
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

        $request = (new ServerRequest('GET', $url, ['Accept' => 'text/html'], null, '1.1', $server))
            ->withAttribute(SessionMiddleware::WITHOUT_SESSION, true);

        return InternalRender::mark($request);
    }

    /** The locale whose URL prefix $path starts with, else the default. */
    private function localeOf(string $path): string
    {
        $first = explode('/', ltrim($path, '/'), 2)[0];

        return $first !== '' && in_array($first, $this->locales, true) ? $first : $this->defaultLocale;
    }
}
