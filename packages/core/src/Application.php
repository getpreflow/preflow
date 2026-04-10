<?php

declare(strict_types=1);

namespace Preflow\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Config\Config;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Error\DevErrorRenderer;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Error\ProdErrorRenderer;
use Preflow\Core\Http\MiddlewarePipeline;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouterInterface;

final class Application
{
    private readonly Container $container;
    private readonly Config $config;
    private readonly MiddlewarePipeline $pipeline;
    private ?RouterInterface $router = null;
    private ?Kernel $kernel = null;

    /** @var callable(Route, ServerRequestInterface): ResponseInterface */
    private $actionDispatcher;

    /** @var callable(Route, ServerRequestInterface): ResponseInterface */
    private $componentRenderer;

    private function __construct(Config $config)
    {
        $this->config = $config;
        $this->container = new Container();
        $this->pipeline = new MiddlewarePipeline();

        $this->container->instance(self::class, $this);
        $this->container->instance(Config::class, $config);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(MiddlewarePipeline::class, $this->pipeline);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config): self
    {
        return new self(new Config($config));
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function registerProvider(ServiceProvider $provider): void
    {
        $this->container->registerProvider($provider);
    }

    public function setRouter(RouterInterface $router): void
    {
        $this->router = $router;
        $this->container->instance(RouterInterface::class, $router);
    }

    /**
     * @param callable(Route, ServerRequestInterface): ResponseInterface $dispatcher
     */
    public function setActionDispatcher(callable $dispatcher): void
    {
        $this->actionDispatcher = $dispatcher;
    }

    /**
     * @param callable(Route, ServerRequestInterface): ResponseInterface $renderer
     */
    public function setComponentRenderer(callable $renderer): void
    {
        $this->componentRenderer = $renderer;
    }

    public function addMiddleware(\Psr\Http\Server\MiddlewareInterface $middleware): void
    {
        $this->pipeline->pipe($middleware);
    }

    public function boot(): void
    {
        $this->container->bootProviders();

        $debug = (bool) $this->config->get('app.debug', false);
        $renderer = $debug ? new DevErrorRenderer() : new ProdErrorRenderer();
        $errorHandler = new ErrorHandler($renderer);

        $this->container->instance(ErrorHandler::class, $errorHandler);

        $this->kernel = new Kernel(
            container: $this->container,
            router: $this->router ?? throw new \RuntimeException('No router configured'),
            pipeline: $this->pipeline,
            errorHandler: $errorHandler,
            actionDispatcher: $this->actionDispatcher ?? throw new \RuntimeException('No action dispatcher configured'),
            componentRenderer: $this->componentRenderer ?? throw new \RuntimeException('No component renderer configured'),
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->kernel === null) {
            throw new \RuntimeException('Application not booted. Call boot() first.');
        }

        return $this->kernel->handle($request);
    }
}
