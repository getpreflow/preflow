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
    ) {
        $this->actionDispatcher = $actionDispatcher;
        $this->componentRenderer = $componentRenderer;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->pipeline->process($request, function (ServerRequestInterface $req): ResponseInterface {
                $route = $this->router->match($req);
                $this->collector?->setRoute($route->mode->value, $route->handler, $route->parameters);

                return match ($route->mode) {
                    RouteMode::Component => ($this->componentRenderer)($route, $req),
                    RouteMode::Action => ($this->actionDispatcher)($route, $req),
                };
            });
        } catch (\Throwable $e) {
            return $this->errorHandler->handleException($e, $request);
        }
    }
}
