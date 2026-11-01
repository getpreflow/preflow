<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Core\Error\DevErrorRenderer;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Http\MiddlewarePipeline;
use Preflow\Core\Kernel;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouteMode;
use Preflow\Core\Routing\RouterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class KernelRouteReuseTest extends TestCase
{
    public function test_kernel_reuses_a_route_set_by_middleware(): void
    {
        $router = new class implements RouterInterface {
            public int $calls = 0;

            public function match(ServerRequestInterface $request): Route
            {
                $this->calls++;
                return new Route(RouteMode::Action, 'Router@handler');
            }
        };

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withAttribute(Route::class, new Route(RouteMode::Action, 'Middleware@handler')));
            }
        });

        $kernel = new Kernel(
            container: new Container(),
            router: $router,
            pipeline: $pipeline,
            errorHandler: new ErrorHandler(new DevErrorRenderer()),
            actionDispatcher: fn (Route $r) => new Response(200, [], $r->handler),
            componentRenderer: fn () => new Response(500),
        );

        $response = $kernel->handle((new Psr17Factory())->createServerRequest('POST', '/x'));

        $this->assertSame(0, $router->calls);
        $this->assertSame('Middleware@handler', (string) $response->getBody());
    }
}
