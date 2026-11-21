<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache\Fixtures;

use Preflow\Core\Container\Container;
use Preflow\Core\Http\CookieQueue;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A site's middleware (inside the page cache) that queues a cookie: before the route answers
 * ('before'), after it ('after'), or before it only for a visitor without the cookie ('missing').
 */
final class CookieRoutes implements MiddlewareInterface
{
    public static string $mode = '';

    public function __construct(private readonly Container $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $queue = $this->container->get(CookieQueue::class);
        if (self::$mode === 'before' || (self::$mode === 'missing' && !isset($request->getCookieParams()['consent']))) {
            $queue->queue('consent=1; Path=/');
        }
        $response = $handler->handle($request);
        if (self::$mode === 'after') {
            $queue->queue('consent=1; Path=/');
        }

        return $response;
    }
}
