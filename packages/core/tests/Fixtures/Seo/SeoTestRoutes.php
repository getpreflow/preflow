<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Seo;

use Nyholm\Psr7\Response;
use Preflow\Core\Application;
use Preflow\View\Geo\GeoMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Routes for the SEO tests that page templates can't express: HTML pages that
 * set a cookie, are private or noindex by header, and /__inner, an HTML page
 * that renders another page through GeoMiddleware::render() on the way.
 */
final class SeoTestRoutes implements MiddlewareInterface
{
    public static ?Application $app = null;

    private const HTML = '<html><head><title>%s</title></head><body><main><p>%s</p></main></body></html>';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/cookie' => self::html('Cookie', ['Set-Cookie' => 'cart=1; Path=/']),
            '/private' => self::html('Private', ['Cache-Control' => 'private, max-age=60']),
            '/no-store' => self::html('No store', ['Cache-Control' => 'no-store']),
            '/hidden' => self::html('Hidden', ['X-Robots-Tag' => 'noindex']),
            '/__inner' => $this->inner($request, $handler),
            default => $handler->handle($request),
        };
    }

    /** @param array<string, string> $headers */
    private static function html(string $title, array $headers = []): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers, sprintf(self::HTML, $title, $title . ' page'));
    }

    private function inner(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $markdown = self::$app?->container()->get(GeoMiddleware::class)->render($request, $handler, '/inner');

        return self::html('Outer', ['X-Inner-Rendered' => $markdown !== null ? 'yes' : 'no']);
    }
}
