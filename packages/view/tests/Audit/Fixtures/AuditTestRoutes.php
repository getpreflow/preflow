<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Routes for PageRendererTest: one that throws, one that keeps the request it was asked with. */
final class AuditTestRoutes implements MiddlewareInterface
{
    public static ?ServerRequestInterface $seen = null;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/boom' => throw new \RuntimeException('Kaboom in a route'),
            '/seen' => $this->seen($request),
            default => $handler->handle($request),
        };
    }

    private function seen(ServerRequestInterface $request): ResponseInterface
    {
        self::$seen = $request;

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><head><title>Seen</title></head><body><p>Seen</p></body></html>');
    }
}
