<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Geo;

use Nyholm\Psr7\Response;
use Preflow\Core\Application;
use Preflow\View\AssetCollector;
use Preflow\View\Geo\GeoCollector;
use Preflow\View\Geo\GeoMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Routes for MarkdownViewTest that page templates can't express: a redirect,
 * a page sent with X-Robots-Tag: noindex, and /__two, which renders two pages
 * through GeoMiddleware::render() in one request and reports what it saw.
 */
final class GeoTestRoutes implements MiddlewareInterface
{
    public static ?Application $app = null;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/old' => new Response(302, ['Location' => '/about']),
            '/hidden' => new Response(200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex'], '<html><head><title>Hidden</title></head><body><main><p>Hidden page</p></main></body></html>'),
            '/__two' => $this->two($request, $handler),
            default => $handler->handle($request),
        };
    }

    private function two(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $container = self::$app->container();
        $geo = $container->get(GeoMiddleware::class);
        $assets = $container->get(AssetCollector::class);
        $assets->addCss('.outer { color: blue; }');

        $alone = $geo->render($request, $handler, '/page-b');
        $a = $geo->render($request, $handler, '/page-a');
        $b = $geo->render($request, $handler, '/page-b');

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'a' => $a,
            'b' => $b,
            'alone' => $alone,
            'css' => $assets->renderCss(),
            'capturing' => $container->get(GeoCollector::class)->capturing(),
        ]));
    }
}
