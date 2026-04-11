<?php

declare(strict_types=1);

namespace Preflow\DevTools\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Core\Debug\DebugCollector;
use Preflow\DevTools\DebugToolbar;

final class DebugToolbarMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly DebugCollector $collector,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $body = (string) $response->getBody();
        if (!str_contains($body, '</body>')) {
            return $response;
        }

        $toolbar = new DebugToolbar();
        $toolbarHtml = $toolbar->render($this->collector);

        $body = str_replace('</body>', $toolbarHtml . '</body>', $body);

        return $response->withBody(\Nyholm\Psr7\Stream::create($body));
    }
}
