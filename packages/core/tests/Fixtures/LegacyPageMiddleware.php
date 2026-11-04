<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A site middleware that answers /legacy itself. */
final class LegacyPageMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $request->getUri()->getPath() === '/legacy'
            ? new Response(200, [], 'legacy page')
            : $handler->handle($request);
    }
}
