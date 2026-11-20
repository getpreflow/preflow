<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache\Fixtures;

use Nyholm\Psr7\Response;
use Preflow\Core\Container\Container;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Writes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Routes inside the page cache for StorageByPurposeTest and VerifyContextTest: a route of $routes
 * answers its path; every other GET is a cacheable page that notes the context it rendered
 * under in $seen.
 */
final class PurposeRoutes implements MiddlewareInterface
{
    /** @var array<string, \Closure(ServerRequestInterface, Container): ResponseInterface> */
    public static array $routes = [];

    /** @var list<array<string, mixed>> */
    public static array $seen = [];

    public function __construct(private readonly Container $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $route = self::$routes[$path] ?? null;
        if ($route !== null) {
            return $route($request, $this->container);
        }
        self::$seen[] = self::context($path);

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><body><p>page ' . htmlspecialchars($path) . '</p></body></html>');
    }

    /** @return array<string, mixed> what the current render runs under */
    public static function context(string $path): array
    {
        $ctx = Contexts::current();

        return [
            'path' => $path,
            'purpose' => $ctx->purpose->value,
            'writes' => $ctx->writes->name,
            'outbox' => $ctx->outbox->name,
            'anonymous' => $ctx->viewer->isAnonymous(),
            'depth' => Contexts::depth(),
            'remote' => $ctx->request?->request?->getServerParams()['REMOTE_ADDR'] ?? null,
        ];
    }

    /** A view counter written on GET (a file next to the site), as a page that writes on GET. */
    public static function count(string $file): int
    {
        if (!Writes::allowed('json', 'counter', 'home', 'save')) {
            return (int) @file_get_contents($file);
        }
        $count = (int) @file_get_contents($file) + 1;
        file_put_contents($file, (string) $count);

        return $count;
    }
}
