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
use Preflow\Core\Http\Emitter;
use Preflow\Core\Http\MiddlewarePipeline;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouteMode;
use Preflow\Core\Routing\RouterInterface;

final class Application
{
    private readonly Container $container;
    private readonly Config $config;
    private readonly MiddlewarePipeline $pipeline;
    private ?RouterInterface $router = null;
    private ?Kernel $kernel = null;
    private string $basePath;

    /** @var callable(Route, ServerRequestInterface): ResponseInterface|null */
    private $actionDispatcher = null;

    /** @var callable(Route, ServerRequestInterface): ResponseInterface|null */
    private $componentRenderer = null;

    private function __construct(Config $config, string $basePath)
    {
        $this->config = $config;
        $this->basePath = rtrim($basePath, '/');
        $this->container = new Container();
        $this->pipeline = new MiddlewarePipeline();

        $this->container->instance(self::class, $this);
        $this->container->instance(Config::class, $config);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(MiddlewarePipeline::class, $this->pipeline);
    }

    /**
     * Create and auto-configure the application.
     *
     * @param string|array $basePath Project root directory, or config array for testing
     */
    public static function create(string|array $basePath = '.'): self
    {
        if (is_array($basePath)) {
            // Legacy/testing: pass config directly
            return new self(new Config($basePath), getcwd() ?: '.');
        }

        $configPath = rtrim($basePath, '/') . '/config/app.php';
        $config = file_exists($configPath) ? require $configPath : [];

        return new self(new Config($config), $basePath);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function basePath(string $sub = ''): string
    {
        return $this->basePath . ($sub ? '/' . ltrim($sub, '/') : '');
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

    /**
     * Boot the application — auto-discovers and wires packages.
     */
    public function boot(): void
    {
        $debug = (bool) $this->config->get('app.debug', false);
        $secretKey = $this->config->get('app.key', 'preflow-default-key-change-me!!');

        // Auto-discover installed packages and wire them up
        $this->bootViewLayer($debug);
        $this->bootComponentLayer($debug, $secretKey);
        $this->bootRouting();
        $this->bootI18n();

        // Register user service providers
        $this->bootProviders();

        // Error handler
        $renderer = $debug ? new DevErrorRenderer() : new ProdErrorRenderer();
        $errorHandler = new ErrorHandler($renderer);
        $this->container->instance(ErrorHandler::class, $errorHandler);

        // Default dispatchers if not set
        $this->ensureActionDispatcher();
        $this->ensureComponentRenderer();

        $this->kernel = new Kernel(
            container: $this->container,
            router: $this->router ?? throw new \RuntimeException('No router configured. Install preflow/routing or set a router manually.'),
            pipeline: $this->pipeline,
            errorHandler: $errorHandler,
            actionDispatcher: $this->actionDispatcher,
            componentRenderer: $this->componentRenderer,
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->kernel === null) {
            throw new \RuntimeException('Application not booted. Call boot() first.');
        }

        // Component endpoint — intercept before routing
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/--component') && $this->container->has('preflow.component_endpoint')) {
            return $this->container->get('preflow.component_endpoint')->handle($request);
        }

        return $this->kernel->handle($request);
    }

    /**
     * Handle request from PHP globals and emit response.
     */
    public function run(): void
    {
        if (class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)
            && class_exists(\Nyholm\Psr7Server\ServerRequestCreator::class)) {
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $creator = new \Nyholm\Psr7Server\ServerRequestCreator($factory, $factory, $factory, $factory);
            $request = $creator->fromGlobals();

            $response = $this->handle($request);
            (new Emitter())->emit($response);
        } else {
            throw new \RuntimeException('Install nyholm/psr7 and nyholm/psr7-server to use run().');
        }
    }

    // -----------------------------------------------------------------------
    // Auto-discovery
    // -----------------------------------------------------------------------

    private function bootViewLayer(bool $debug): void
    {
        if (!class_exists(\Preflow\View\Twig\TwigEngine::class)) {
            return;
        }

        $nonce = new \Preflow\View\NonceGenerator();
        $assets = new \Preflow\View\AssetCollector($nonce, isProd: !$debug);
        $this->container->instance(\Preflow\View\AssetCollector::class, $assets);
        $this->container->instance(\Preflow\View\NonceGenerator::class, $nonce);

        $pagesDir = $this->basePath('app/pages');
        $templateDirs = is_dir($pagesDir) ? [$pagesDir] : [];

        $engine = new \Preflow\View\Twig\TwigEngine(
            templateDirs: $templateDirs,
            assetCollector: $assets,
            debug: $debug,
        );

        $this->container->instance(\Preflow\View\TemplateEngineInterface::class, $engine);
        $this->container->instance(\Preflow\View\Twig\TwigEngine::class, $engine);
    }

    private function bootComponentLayer(bool $debug, string $secretKey): void
    {
        if (!class_exists(\Preflow\Components\ComponentRenderer::class)) {
            return;
        }

        if (!$this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
            return;
        }

        $engine = $this->container->get(\Preflow\View\Twig\TwigEngine::class);

        $errorBoundary = new \Preflow\Components\ErrorBoundary(debug: $debug);
        $renderer = new \Preflow\Components\ComponentRenderer(
            $this->container->get(\Preflow\View\TemplateEngineInterface::class),
            $errorBoundary,
        );
        $this->container->instance(\Preflow\Components\ComponentRenderer::class, $renderer);

        // Auto-discover components
        $componentMap = $this->discoverComponents();

        // Register {{ component() }} Twig function
        $engine->getTwig()->addExtension(
            new \Preflow\Components\Twig\ComponentExtension($renderer, $componentMap)
        );

        // HTMX driver
        if (class_exists(\Preflow\Htmx\HtmxDriver::class)) {
            $responseHeaders = new \Preflow\Htmx\ResponseHeaders();
            $htmxDriver = new \Preflow\Htmx\HtmxDriver($responseHeaders);
            $componentToken = new \Preflow\Htmx\ComponentToken($secretKey);

            $this->container->instance(\Preflow\Htmx\ResponseHeaders::class, $responseHeaders);
            $this->container->instance(\Preflow\Htmx\HypermediaDriver::class, $htmxDriver);
            $this->container->instance(\Preflow\Htmx\HtmxDriver::class, $htmxDriver);
            $this->container->instance(\Preflow\Htmx\ComponentToken::class, $componentToken);

            // Register HTMX library script tag in <head>
            $assets = $this->container->get(\Preflow\View\AssetCollector::class);
            $assets->addHeadTag($htmxDriver->assetTag());

            // Register {{ hd.post(...) }} Twig helper
            $engine->getTwig()->addExtension(
                new \Preflow\Htmx\Twig\HdExtension($htmxDriver, $componentToken)
            );

            // Component endpoint
            $container = $this->container;
            $endpoint = new \Preflow\Htmx\ComponentEndpoint(
                token: $componentToken,
                renderer: $renderer,
                driver: $htmxDriver,
                componentFactory: function (string $class, array $props) use ($container) {
                    $component = $container->has($class) ? $container->get($class) : new $class();
                    $component->setProps($props);
                    return $component;
                },
            );
            $this->container->instance('preflow.component_endpoint', $endpoint);
            $this->container->instance(\Preflow\Htmx\ComponentEndpoint::class, $endpoint);
        }
    }

    private function bootRouting(): void
    {
        if (!class_exists(\Preflow\Routing\Router::class)) {
            return;
        }

        if ($this->router !== null) {
            return; // manually configured
        }

        $pagesDir = $this->basePath('app/pages');
        $controllers = $this->discoverControllers();

        $router = new \Preflow\Routing\Router(
            pagesDir: is_dir($pagesDir) ? $pagesDir : null,
            controllers: $controllers,
        );

        $this->setRouter($router);
    }

    private function bootI18n(): void
    {
        if (!class_exists(\Preflow\I18n\Translator::class)) {
            return;
        }

        $langDir = $this->basePath('lang');
        if (!is_dir($langDir)) {
            return;
        }

        $locale = $this->config->get('app.locale', 'en');
        $i18nConfig = [];
        $i18nPath = $this->basePath('config/i18n.php');
        if (file_exists($i18nPath)) {
            $i18nConfig = require $i18nPath;
        }

        $fallback = $i18nConfig['fallback'] ?? $locale;
        $translator = new \Preflow\I18n\Translator($langDir, $locale, $fallback);
        $this->container->instance(\Preflow\I18n\Translator::class, $translator);

        // Register t()/tc() Twig functions
        if ($this->container->has(\Preflow\View\Twig\TwigEngine::class)) {
            $engine = $this->container->get(\Preflow\View\Twig\TwigEngine::class);
            $engine->getTwig()->addExtension(
                new \Preflow\I18n\Twig\TranslationExtension($translator)
            );
        }

        // Locale middleware
        if (class_exists(\Preflow\I18n\LocaleMiddleware::class)) {
            $available = $i18nConfig['available'] ?? [$locale];
            $strategy = $i18nConfig['url_strategy'] ?? 'prefix';
            $this->addMiddleware(new \Preflow\I18n\LocaleMiddleware(
                $translator, $available, $locale, $strategy
            ));
        }
    }

    private function bootProviders(): void
    {
        $providersPath = $this->basePath('config/providers.php');
        if (file_exists($providersPath)) {
            $providers = require $providersPath;
            foreach ($providers as $providerClass) {
                if (class_exists($providerClass)) {
                    $provider = new $providerClass();
                    $this->registerProvider($provider);
                }
            }
        }

        $this->container->bootProviders();
    }

    /**
     * Auto-discover component classes in app/Components/.
     *
     * @return array<string, class-string> Short name → FQCN
     */
    private function discoverComponents(): array
    {
        $dir = $this->basePath('app/Components');
        if (!is_dir($dir)) {
            return [];
        }

        $map = [];
        foreach (new \DirectoryIterator($dir) as $item) {
            if (!$item->isDir() || $item->isDot()) {
                continue;
            }

            $name = $item->getFilename();
            $phpFile = $item->getPathname() . '/' . $name . '.php';

            if (file_exists($phpFile)) {
                $class = 'App\\Components\\' . $name . '\\' . $name;

                // Also check flat namespace: App\Components\Name
                $classFlat = 'App\\Components\\' . $name;

                if (class_exists($class)) {
                    $map[$name] = $class;
                } elseif (class_exists($classFlat)) {
                    $map[$name] = $classFlat;
                }
            }
        }

        return $map;
    }

    /**
     * Auto-discover controller classes in app/Controllers/.
     *
     * @return string[] Controller FQCNs
     */
    private function discoverControllers(): array
    {
        $dir = $this->basePath('app/Controllers');
        if (!is_dir($dir)) {
            return [];
        }

        $controllers = [];
        $this->scanControllersRecursive($dir, 'App\\Controllers', $controllers);

        return $controllers;
    }

    /**
     * @param string[] $controllers
     */
    private function scanControllersRecursive(string $dir, string $namespace, array &$controllers): void
    {
        foreach (new \DirectoryIterator($dir) as $item) {
            if ($item->isDot()) {
                continue;
            }

            if ($item->isDir()) {
                $this->scanControllersRecursive(
                    $item->getPathname(),
                    $namespace . '\\' . $item->getFilename(),
                    $controllers,
                );
                continue;
            }

            if ($item->getExtension() !== 'php') {
                continue;
            }

            $class = $namespace . '\\' . $item->getBasename('.php');

            if (class_exists($class)) {
                // Only include classes that have a Route attribute
                $ref = new \ReflectionClass($class);
                if ($ref->getAttributes(\Preflow\Routing\Attributes\Route::class) !== []) {
                    $controllers[] = $class;
                }
            }
        }
    }

    private function ensureActionDispatcher(): void
    {
        if ($this->actionDispatcher !== null) {
            return;
        }

        $container = $this->container;
        $this->actionDispatcher = function (Route $route, ServerRequestInterface $request) use ($container): ResponseInterface {
            [$class, $method] = explode('@', $route->handler);

            $controller = $container->has($class) ? $container->get($class) : new $class();
            $response = $controller->{$method}($request);

            if ($response instanceof ResponseInterface) {
                return $response;
            }

            return new \Nyholm\Psr7\Response(200, ['Content-Type' => 'text/html'], (string) $response);
        };
    }

    private function ensureComponentRenderer(): void
    {
        if ($this->componentRenderer !== null) {
            return;
        }

        $container = $this->container;
        $this->componentRenderer = function (Route $route, ServerRequestInterface $request) use ($container): ResponseInterface {
            if ($container->has(\Preflow\View\Twig\TwigEngine::class)) {
                $engine = $container->get(\Preflow\View\Twig\TwigEngine::class);
                $html = $engine->render($route->handler, [
                    'route' => (object) $route->parameters,
                ]);

                // Post-process: inject collected assets into the HTML
                if ($container->has(\Preflow\View\AssetCollector::class)) {
                    $assets = $container->get(\Preflow\View\AssetCollector::class);
                    $html = $this->injectAssets($html, $assets);
                }

                return new \Nyholm\Psr7\Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
            }

            return new \Nyholm\Psr7\Response(500, [], 'No template engine configured. Install preflow/view.');
        };
    }

    /**
     * Inject collected CSS/JS assets into the HTML document.
     * CSS + head JS + library tags → before </head>
     * Body JS → before </body>
     */
    private function injectAssets(string $html, \Preflow\View\AssetCollector $assets): string
    {
        $headContent = $assets->renderHead();
        $bodyContent = $assets->renderAssets();

        if ($headContent !== '') {
            $html = str_replace('</head>', $headContent . '</head>', $html);
        }

        if ($bodyContent !== '') {
            $html = str_replace('</body>', $bodyContent . '</body>', $html);
        }

        return $html;
    }
}
