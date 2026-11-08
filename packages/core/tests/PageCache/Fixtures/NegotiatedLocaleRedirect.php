<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A site middleware that sends visitors of the unprefixed home page to their language
 * (Accept-Language), and says so with Vary on both answers.
 */
final class NegotiatedLocaleRedirect implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== '/negotiated' || $request->getAttribute('locale') !== 'en') {
            return $handler->handle($request);
        }
        if (str_starts_with($request->getHeaderLine('Accept-Language'), 'de')) {
            return new Response(302, ['Location' => '/de/negotiated', 'Vary' => 'Accept-Language']);
        }

        return $handler->handle($request)->withHeader('Vary', 'Accept-Language');
    }
}
