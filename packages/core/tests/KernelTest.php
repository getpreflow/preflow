<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Core\Container\Container;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Error\DevErrorRenderer;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\MiddlewarePipeline;
use Preflow\Core\Kernel;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouteMode;
use Preflow\Core\Routing\RouterInterface;

class StubRouter implements RouterInterface
{
    public function __construct(
        private readonly ?Route $route = null,
    ) {}

    public function match(ServerRequestInterface $request): Route
    {
        if ($this->route === null) {
            throw new NotFoundHttpException();
        }
        return $this->route;
    }
}

class StubActionHandler
{
    public function handle(Route $route, ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'action:' . $route->handler);
    }
}

class StubComponentRenderer
{
    public function render(Route $route, ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'component:' . $route->handler);
    }
}

class TimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        return $response->withHeader('X-Timing', 'applied');
    }
}

class RouteSpyMiddleware implements MiddlewareInterface
{
    public static ?Route $seen = null;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        self::$seen = $request->getAttribute(Route::class);
        return $handler->handle($request);
    }
}

final class KernelTest extends TestCase
{
    private function createRequest(string $method = 'GET', string $uri = '/'): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest($method, $uri);
    }

    private function createKernel(
        ?RouterInterface $router = null,
        array $middleware = [],
    ): Kernel {
        $container = new Container();

        $actionHandler = new StubActionHandler();
        $componentRenderer = new StubComponentRenderer();
        $container->instance(StubActionHandler::class, $actionHandler);
        $container->instance(StubComponentRenderer::class, $componentRenderer);

        $pipeline = new MiddlewarePipeline();
        foreach ($middleware as $mw) {
            $pipeline->pipe($mw);
        }

        $errorHandler = new ErrorHandler(new DevErrorRenderer());

        return new Kernel(
            container: $container,
            router: $router ?? new StubRouter(),
            pipeline: $pipeline,
            errorHandler: $errorHandler,
            actionDispatcher: fn (Route $r, ServerRequestInterface $req) => $actionHandler->handle($r, $req),
            componentRenderer: fn (Route $r, ServerRequestInterface $req) => $componentRenderer->render($r, $req),
        );
    }

    public function test_dispatches_action_mode(): void
    {
        $route = new Route(
            mode: RouteMode::Action,
            handler: 'TestController@index',
        );

        $kernel = $this->createKernel(new StubRouter($route));
        $response = $kernel->handle($this->createRequest());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('action:TestController@index', (string) $response->getBody());
    }

    public function test_dispatches_component_mode(): void
    {
        $route = new Route(
            mode: RouteMode::Component,
            handler: 'pages/index',
        );

        $kernel = $this->createKernel(new StubRouter($route));
        $response = $kernel->handle($this->createRequest());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('component:pages/index', (string) $response->getBody());
    }

    public function test_middleware_is_applied(): void
    {
        $route = new Route(mode: RouteMode::Action, handler: 'test');

        $kernel = $this->createKernel(
            router: new StubRouter($route),
            middleware: [new TimingMiddleware()],
        );

        $response = $kernel->handle($this->createRequest());

        $this->assertSame('applied', $response->getHeaderLine('X-Timing'));
    }

    public function test_not_found_returns_404(): void
    {
        $kernel = $this->createKernel(new StubRouter(null));

        $response = $kernel->handle($this->createRequest());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_unexpected_error_returns_500(): void
    {
        $router = new class implements RouterInterface {
            public function match(ServerRequestInterface $request): Route
            {
                throw new \RuntimeException('Unexpected failure');
            }
        };

        $kernel = $this->createKernel($router);
        $response = $kernel->handle($this->createRequest());

        $this->assertSame(500, $response->getStatusCode());
    }

    public function test_route_middleware_sees_the_matched_route(): void
    {
        $route = new Route(mode: RouteMode::Action, handler: 'X@y', parameters: ['type' => 'page'], middleware: [RouteSpyMiddleware::class]);
        RouteSpyMiddleware::$seen = null;

        $this->createKernel(new StubRouter($route))->handle($this->createRequest());

        $this->assertSame($route, RouteSpyMiddleware::$seen);
    }

    public function test_component_paths_go_to_the_endpoint_through_the_pipeline(): void
    {
        $container = new Container();
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new TimingMiddleware());
        $router = new class implements RouterInterface {
            public bool $called = false;
            public function match(ServerRequestInterface $request): Route
            {
                $this->called = true;
                throw new NotFoundHttpException();
            }
        };
        $kernel = new Kernel(
            container: $container,
            router: $router,
            pipeline: $pipeline,
            errorHandler: new ErrorHandler(new DevErrorRenderer()),
            actionDispatcher: fn () => new Response(500),
            componentRenderer: fn () => new Response(500),
            componentEndpoint: fn (ServerRequestInterface $r) => new Response(200, [], 'endpoint'),
        );

        $response = $kernel->handle($this->createRequest('POST', '/--component/action'));

        $this->assertSame('endpoint', (string) $response->getBody());
        $this->assertSame('applied', $response->getHeaderLine('X-Timing'));
        $this->assertFalse($router->called);
    }

    public function test_endpoint_exceptions_use_the_error_handler(): void
    {
        $kernel = new Kernel(
            container: new Container(),
            router: new StubRouter(),
            pipeline: new MiddlewarePipeline(),
            errorHandler: new ErrorHandler(new DevErrorRenderer()),
            actionDispatcher: fn () => new Response(500),
            componentRenderer: fn () => new Response(500),
            componentEndpoint: fn () => throw new \Preflow\Core\Exceptions\SecurityException('Missing component token.'),
        );

        $this->assertSame(403, $kernel->handle($this->createRequest('GET', '/--component/render'))->getStatusCode());
    }
}
