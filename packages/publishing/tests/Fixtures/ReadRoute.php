<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests\Fixtures;

use Nyholm\Psr7\Response;
use Preflow\Core\Container\Container;
use Preflow\Publishing\LiveContent;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Test middleware: '/read' answers the title of post p1 as LiveContent reads it (what it saw is
 * kept in $seen, an exception as its class); '/outer' asks the app for '/read' in-process.
 */
final class ReadRoute implements MiddlewareInterface
{
    /** @var list<string> */
    public static array $seen = [];

    public function __construct(private readonly Container $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/read' => $this->read(),
            '/outer' => $this->container->get(\Preflow\Core\Application::class)->handle($request->withUri($request->getUri()->withPath('/read'))),
            default => $handler->handle($request),
        };
    }

    private function read(): ResponseInterface
    {
        try {
            self::$seen[] = (string) ($this->container->get(LiveContent::class)->find('post', 'p1')?->get('title')['en'] ?? '');
        } catch (\Throwable $e) {
            self::$seen[] = $e::class;
        }

        return new Response(200, [], 'read');
    }
}
