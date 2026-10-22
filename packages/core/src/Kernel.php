<?php

declare(strict_types=1);

namespace Preflow\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Container\Container;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Http\MiddlewarePipeline;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouteMode;
use Preflow\Core\Routing\RouterInterface;

final class Kernel
{
    /** @var callable(Route, ServerRequestInterface): ResponseInterface */
    private $actionDispatcher;

    /** @var callable(Route, ServerRequestInterface): ResponseInterface */
    private $componentRenderer;

    /**
     * @param callable(Route, ServerRequestInterface): ResponseInterface $actionDispatcher
     * @param callable(Route, ServerRequestInterface): ResponseInterface $componentRenderer
     */
    public function __construct(
        private readonly Container $container,
        private readonly RouterInterface $router,
        private readonly MiddlewarePipeline $pipeline,
        private readonly ErrorHandler $errorHandler,
        callable $actionDispatcher,
        callable $componentRenderer,
        private readonly ?DebugCollector $collector = null,
        private readonly ?\Preflow\Core\Http\SecurityHeadersMiddleware $securityHeaders = null,
        /** @var (\Closure(ServerRequestInterface): ResponseInterface)|null the /--component endpoint */
        private readonly ?\Closure $componentEndpoint = null,
        /** cookies queued before an exception (a rotated remember-me cookie) still reach the error response */
        private readonly ?\Preflow\Core\Http\CookieQueue $cookies = null,
    ) {
        $this->actionDispatcher = $actionDispatcher;
        $this->componentRenderer = $componentRenderer;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->pipeline->process($request, function (ServerRequestInterface $req): ResponseInterface {
                // Component requests get the same middleware (headers, session, auth) as pages.
                if ($this->componentEndpoint !== null && str_starts_with($req->getUri()->getPath(), '/--component')) {
                    return ($this->componentEndpoint)($req);
                }
                $route = $this->router->match($req);
                // Route middleware (e.g. permission checks) can read the matched handler and parameters.
                $req = $req->withAttribute(Route::class, $route);
                $this->collector?->setRoute($route->mode->value, $route->handler, $route->parameters);

                $dispatch = fn(ServerRequestInterface $r): ResponseInterface => match ($route->mode) {
                    RouteMode::Component => ($this->componentRenderer)($route, $r),
                    RouteMode::Action => ($this->actionDispatcher)($route, $r),
                };

                // Execute route-level middleware if any
                if ($route->middleware !== []) {
                    $routePipeline = new MiddlewarePipeline();
                    foreach ($route->middleware as $mwClass) {
                        $mw = $this->container->has($mwClass)
                            ? $this->container->get($mwClass)
                            : $this->container->make($mwClass);
                        $routePipeline->pipe($mw);
                    }
                    return $routePipeline->process($req, $dispatch);
                }

                return $dispatch($req);
            });
        } catch (\Throwable $e) {
            // Error responses bypass the pipeline, so add the queued cookies and the security headers here.
            $response = $this->errorHandler->handleException($e, $request);
            $response = $this->cookies?->apply($response) ?? $response;
            return $this->securityHeaders?->decorate($request, $response) ?? $response;
        }
    }
}
