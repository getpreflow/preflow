<?php

declare(strict_types=1);

namespace Preflow\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\Exceptions\ContainerException;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Http\MiddlewarePipeline;
use Preflow\Core\Routing\ConfiguredMiddleware;
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
        /**
         * cookies queued before an exception (a rotated remember-me cookie) still reach the error
         * response: those of the current render frame only (CookieQueue::ofCurrentFrame()), never
         * those of a request around a failed internal render; since 0.81.1 also the response of a
         * frame that sets them ($setsQueuedCookies)
         */
        private readonly ?\Preflow\Core\Http\CookieQueue $cookies = null,
        /** a cross-origin API call must be able to read its error (a 401, a 422) */
        private readonly ?\Preflow\Core\Http\Api\CorsMiddleware $cors = null,
        /**
         * since 0.81.0: the headers the current render frame's page or components added
         * (ResponseHeaderHints::ofCurrentFrame()), put on the route's response inside the pipeline,
         * so the page cache and every other middleware see them, and the restrictive ones on the error page
         */
        private readonly ?\Preflow\Core\Http\ResponseHeaderHints $hints = null,
        /**
         * since 0.81.1: whether the current render frame's response takes the cookies it queued
         * ($cookies): when it does, they go on the route's response inside the pipeline (so the
         * page cache and every other middleware see them) and what middleware queued on the way
         * out on the pipeline's answer. Null: only the error response takes them, as before.
         *
         * @var (\Closure(): bool)|null
         */
        private readonly ?\Closure $setsQueuedCookies = null,
    ) {
        $this->actionDispatcher = $actionDispatcher;
        $this->componentRenderer = $componentRenderer;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Every answer to a request that carries a preview token, whichever middleware gives it (a
        // redirect of the locale or the clean-path middleware, which run before the token is read),
        // is private, no-store, no-referrer and noindex (since 0.81.0).
        $plain = \Preflow\Core\Context\Preview\PreviewToken::strip($request);
        $response = $this->answer($request, $plain);
        if ($plain !== $request) {
            foreach (\Preflow\Core\Context\Preview\PreviewToken::RESPONSE_HEADERS as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /** The pipeline's answer, or the error page for what it threw ($plain: the request without a preview token). */
    private function answer(ServerRequestInterface $request, ServerRequestInterface $plain): ResponseInterface
    {
        try {
            $response = $this->pipeline->process($request, fn (ServerRequestInterface $req): ResponseInterface => $this->withQueuedCookies($this->withHints($this->dispatch($req))));

            // What middleware queued after the route answered (or instead of it).
            return $this->withQueuedCookies($response);
        } catch (\Throwable $e) {
            // The render answers with the error page; who asked for it (Application::render()) still learns why.
            if (\Preflow\Core\Context\Contexts::depth() > 0) {
                \Preflow\Core\Context\Contexts::frame()->recordFailure($e);
            }
            // Error responses bypass the pipeline, so add the queued cookies (this frame's), its restrictive header hints, CORS and the security headers here.
            // A preview token is never shown on an error page (the debug page prints the request): it
            // gets the request without it (handle() makes the answer no-store, no-referrer, noindex).
            $response = $this->errorHandler->handleException($e, $plain);
            $response = $this->cookies?->apply($response) ?? $response;
            // Only hints that make the error less cacheable: a failure is never made cacheable.
            $response = $this->hints?->applyRestrictiveTo($response) ?? $response;
            $response = $this->cors?->decorate($request, $response) ?? $response;
            return $this->securityHeaders?->decorate($request, $response) ?? $response;
        }
    }

    /** The route's response for $req, after the pipeline. */
    private function dispatch(ServerRequestInterface $req): ResponseInterface
    {
        // Component requests get the same middleware (headers, session, auth) as pages.
        if ($this->componentEndpoint !== null && str_starts_with($req->getUri()->getPath(), rtrim(\Preflow\Core\Http\Csrf\CsrfConfig::COMPONENT_PREFIX, '/'))) {
            return ($this->componentEndpoint)($req);
        }
        // Middleware that already matched (CSRF) leaves the route on the request.
        $route = $req->getAttribute(Route::class);
        if (!$route instanceof Route) {
            $route = $this->router->match($req);
        }
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
            foreach ($route->middleware as $mw) {
                $routePipeline->pipe($this->routeMiddleware($mw));
            }
            return $routePipeline->process($req, $dispatch);
        }

        return $dispatch($req);
    }

    /** $response with the headers the current frame's page or components added (ResponseHeaderHints). */
    private function withHints(ResponseInterface $response): ResponseInterface
    {
        return $this->hints?->applyTo($response) ?? $response;
    }

    /** $response with the cookies the current frame queued, where its response takes them ($setsQueuedCookies); applying empties the queue. */
    private function withQueuedCookies(ResponseInterface $response): ResponseInterface
    {
        if ($this->cookies === null || $this->setsQueuedCookies === null || !($this->setsQueuedCookies)()) {
            return $response;
        }

        return $this->cookies->apply($response);
    }

    /** A class name: the container's instance, else autowired. With arguments: a new instance, the arguments winning over autowiring. */
    private function routeMiddleware(string|ConfiguredMiddleware $mw): MiddlewareInterface
    {
        if (!$mw instanceof ConfiguredMiddleware) {
            return $this->container->has($mw) ? $this->container->get($mw) : $this->container->make($mw);
        }
        $class = $mw->middlewareClass();
        try {
            $built = $this->container->make($class, $mw->arguments());
        } catch (\TypeError $e) {
            throw new ContainerException("Route middleware [{$class}] cannot be built with the arguments ["
                . implode(', ', array_keys($mw->arguments())) . ']: ' . $e->getMessage(), 0, $e);
        }
        if (!$built instanceof MiddlewareInterface) {
            throw new ContainerException("Route middleware [{$class}] is not a middleware (" . MiddlewareInterface::class . ').');
        }

        return $built;
    }
}
