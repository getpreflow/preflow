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
use Preflow\Core\Http\RequestContext;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Core\DebugLevel;
use Preflow\Core\EnvLoader;
use Preflow\Core\Routing\Route;
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

        EnvLoader::load(rtrim($basePath, '/') . '/.env');

        $configPath = rtrim($basePath, '/') . '/config/app.php';
        $appConfig = file_exists($configPath) ? require $configPath : [];

        return new self(new Config(['app' => $appConfig]), $basePath);
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
        $debug = DebugLevel::from((int) $this->config->get('app.debug', 0));
        $secretKey = $this->config->get('app.key', 'preflow-default-key-change-me!!');

        // Debug collector — created early so subsystems can log into it
        $collector = null;
        if ($debug->isDebug()) {
            $collector = new DebugCollector();
            $this->container->instance(DebugCollector::class, $collector);
        }

        // JSON body parsing — must run before session, CSRF, and auth middleware
        $this->pipeline->pipe(new \Preflow\Core\Http\JsonBodyMiddleware());

        // Auto-discover installed packages and wire them up
        $this->bootDataLayer($collector);
        $this->bootViewLayer($debug);
        $this->bootComponentLayer($debug, $secretKey, $collector);
        $this->bootRouting();
        $this->bootI18n();
        $this->bootSession();
        $this->bootCsrf();
        $this->bootAuth();

        // Register user service providers
        $this->bootProviders();

        // Load user-defined middleware from config/middleware.php
        $this->bootMiddleware();

        // Error handler
        $renderer = $debug->isDebug()
            ? new DevErrorRenderer($collector)
            : new ProdErrorRenderer();
        $errorHandler = new ErrorHandler($renderer);
        $this->container->instance(ErrorHandler::class, $errorHandler);

        // Default dispatchers if not set
        $this->ensureActionDispatcher();
        $this->ensureComponentRenderer();

        // Debug toolbar middleware — must be last so it wraps the full response
        if ($debug->isDebug() && $collector !== null && class_exists(\Preflow\DevTools\Http\DebugToolbarMiddleware::class)) {
            $this->addMiddleware(new \Preflow\DevTools\Http\DebugToolbarMiddleware($collector));
        }

        $this->kernel = new Kernel(
            container: $this->container,
            router: $this->router ?? throw new \RuntimeException('No router configured. Install preflow/routing or set a router manually.'),
            pipeline: $this->pipeline,
            errorHandler: $errorHandler,
            actionDispatcher: $this->actionDispatcher,
            componentRenderer: $this->componentRenderer,
            collector: $collector,
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->kernel === null) {
            throw new \RuntimeException('Application not booted. Call boot() first.');
        }

        // Make request context available to components via DI
        $this->container->instance(RequestContext::class, new RequestContext(
            path: $request->getUri()->getPath(),
            method: $request->getMethod(),
        ));

        // Component endpoint — intercept before routing
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/--component') && $this->container->has('preflow.component_endpoint')) {
            return $this->container->get('preflow.component_endpoint')->handle($request);
        }

        $response = $this->kernel->handle($request);

        // Log asset stats to debug collector
        if ($this->container->has(DebugCollector::class) && $this->container->has(\Preflow\View\AssetCollector::class)) {
            $collector = $this->container->get(DebugCollector::class);
            $assets = $this->container->get(\Preflow\View\AssetCollector::class);
            $collector->setAssets(
                $assets->getCssCount(),
                $assets->getJsCount(),
                $assets->getCssBytes(),
                $assets->getJsBytes(),
            );
        }

        return $response;
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

    private function bootDataLayer(?DebugCollector $collector = null): void
    {
        if (!class_exists(\Preflow\Data\DataManager::class)) {
            return;
        }

        $dataConfigPath = $this->basePath('config/data.php');
        if (!file_exists($dataConfigPath)) {
            return;
        }

        $dataConfig = require $dataConfigPath;
        $drivers = [];

        foreach ($dataConfig['drivers'] ?? [] as $name => $driverConfig) {
            $drivers[$name] = match ($name) {
                'sqlite' => $this->createSqliteDriver($driverConfig, $collector),
                'mysql' => $this->createMysqlDriver($driverConfig, $collector),
                'json' => new \Preflow\Data\Driver\JsonFileDriver(
                    $driverConfig['path'] ?? $this->basePath('storage/data'),
                ),
                default => null,
            };
        }

        $drivers = array_filter($drivers);

        $default = $dataConfig['default'] ?? 'sqlite';
        if (isset($drivers[$default])) {
            $drivers['default'] = $drivers[$default];
        }

        // TypeRegistry for dynamic models
        $typeRegistry = null;
        $modelsPath = $dataConfig['models_path'] ?? $this->basePath('config/models');
        if (is_dir($modelsPath)) {
            $typeRegistry = new \Preflow\Data\TypeRegistry($modelsPath);
            $this->container->instance(\Preflow\Data\TypeRegistry::class, $typeRegistry);
        }

        $dataManager = new \Preflow\Data\DataManager($drivers, 'default', $typeRegistry);
        $this->container->instance(\Preflow\Data\DataManager::class, $dataManager);
    }

    private function createSqliteDriver(array $config, ?DebugCollector $collector = null): \Preflow\Data\Driver\SqliteDriver
    {
        $path = $config['path'] ?? $this->basePath('storage/data/app.sqlite');
        $dbDir = dirname($path);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        $dsn = str_starts_with($path, 'sqlite:') ? $path : 'sqlite:' . $path;
        $pdo = new \PDO($dsn);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->container->instance(\PDO::class, $pdo);
        return new \Preflow\Data\Driver\SqliteDriver($pdo, $collector);
    }

    private function createMysqlDriver(array $config, ?DebugCollector $collector = null): \Preflow\Data\Driver\MysqlDriver
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'] ?? getenv('DB_HOST') ?: '127.0.0.1',
            $config['port'] ?? getenv('DB_PORT') ?: '3306',
            $config['database'] ?? getenv('DB_NAME') ?: '',
        );
        $pdo = new \PDO(
            $dsn,
            $config['username'] ?? getenv('DB_USER') ?: 'root',
            $config['password'] ?? getenv('DB_PASS') ?: '',
        );
        $this->container->instance(\PDO::class, $pdo);
        return new \Preflow\Data\Driver\MysqlDriver($pdo, $collector);
    }

    private function bootViewLayer(DebugLevel $debug): void
    {
        $nonce = new \Preflow\View\NonceGenerator();
        $assets = new \Preflow\View\AssetCollector($nonce, isProd: !$debug->isDebug());
        $this->container->instance(\Preflow\View\AssetCollector::class, $assets);
        $this->container->instance(\Preflow\View\NonceGenerator::class, $nonce);

        $pagesDir = $this->basePath('app/pages');
        $templateDirs = is_dir($pagesDir) ? [$pagesDir] : [];

        // Also register the project root so that templates in app/pages can
        // extend layout files via "templates/_base.twig" etc.
        $projectRoot = $this->basePath();
        if (!in_array($projectRoot, $templateDirs, true)) {
            $templateDirs[] = $projectRoot;
        }

        $engineName = $this->config->get('app.engine', 'twig');
        $engine = $this->createTemplateEngine($engineName, $templateDirs, $assets, $debug);

        if ($engine === null) {
            return;
        }

        $this->container->instance(\Preflow\View\TemplateEngineInterface::class, $engine);
    }

    private function createTemplateEngine(
        string $name,
        array $templateDirs,
        \Preflow\View\AssetCollector $assets,
        DebugLevel $debug,
    ): ?\Preflow\View\TemplateEngineInterface {
        return match ($name) {
            'twig' => class_exists(\Preflow\Twig\TwigEngine::class)
                ? new \Preflow\Twig\TwigEngine(
                    templateDirs: $templateDirs,
                    assetCollector: $assets,
                    debug: $debug->isDebug(),
                )
                : null,
            'blade' => class_exists(\Preflow\Blade\BladeEngine::class)
                ? new \Preflow\Blade\BladeEngine(
                    templateDirs: $templateDirs,
                    assetCollector: $assets,
                    debug: $debug->isDebug(),
                )
                : null,
            default => throw new \RuntimeException("Unknown template engine: {$name}. Supported: twig, blade"),
        };
    }

    private function bootComponentLayer(DebugLevel $debug, string $secretKey, ?DebugCollector $collector = null): void
    {
        if (!class_exists(\Preflow\Components\ComponentRenderer::class)) {
            return;
        }

        if (!$this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
            return;
        }

        $engine = $this->container->get(\Preflow\View\TemplateEngineInterface::class);

        $errorBoundary = new \Preflow\Components\ErrorBoundary(debug: $debug);
        $renderer = new \Preflow\Components\ComponentRenderer($engine, $errorBoundary, $collector);
        $this->container->instance(\Preflow\Components\ComponentRenderer::class, $renderer);

        // Auto-discover components
        $componentMap = $this->discoverComponents();

        // Component factory — uses DI container for constructor injection
        $container = $this->container;
        $componentFactory = function (string $class, array $props) use ($container) {
            $component = $container->has($class) ? $container->get($class) : $container->make($class);
            $component->setProps($props);
            return $component;
        };

        // Register component template functions
        $componentProvider = new \Preflow\Components\ComponentsExtensionProvider(
            $renderer, $componentMap, $componentFactory
        );
        $this->registerExtensionProvider($engine, $componentProvider);

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

            // Register hd template functions
            $htmxProvider = new \Preflow\Htmx\HtmxExtensionProvider($htmxDriver, $componentToken);
            $this->registerExtensionProvider($engine, $htmxProvider);

            // Component endpoint
            $container = $this->container;
            $endpoint = new \Preflow\Htmx\ComponentEndpoint(
                token: $componentToken,
                renderer: $renderer,
                driver: $htmxDriver,
                componentFactory: function (string $class, array $props) use ($container) {
                    $component = $container->has($class) ? $container->get($class) : $container->make($class);
                    $component->setProps($props);
                    return $component;
                },
                assetCollector: $this->container->get(\Preflow\View\AssetCollector::class),
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

        // Register t()/tc() template functions
        if ($this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
            $engine = $this->container->get(\Preflow\View\TemplateEngineInterface::class);
            $translationProvider = new \Preflow\I18n\TranslationExtensionProvider($translator);
            $this->registerExtensionProvider($engine, $translationProvider);
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

    private function bootMiddleware(): void
    {
        $middlewarePath = $this->basePath('config/middleware.php');
        if (!file_exists($middlewarePath)) {
            return;
        }

        $middlewareClasses = require $middlewarePath;
        foreach ($middlewareClasses as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $mw = $this->container->has($class)
                ? $this->container->get($class)
                : $this->container->make($class);
            $this->addMiddleware($mw);
        }
    }

    private function bootSession(): void
    {
        if (!class_exists(\Preflow\Core\Http\Session\NativeSession::class)) {
            return;
        }

        $authConfigPath = $this->basePath('config/auth.php');
        if (!file_exists($authConfigPath)) {
            return;
        }

        $authConfig = require $authConfigPath;
        $sessionConfig = $authConfig['session'] ?? [];

        if ($sessionConfig === []) {
            return;
        }

        $session = new \Preflow\Core\Http\Session\NativeSession($sessionConfig);
        $session->start();
        $this->container->instance(\Preflow\Core\Http\Session\SessionInterface::class, $session);

        $this->addMiddleware(new \Preflow\Core\Http\Session\SessionMiddleware($session));
    }

    private function bootCsrf(): void
    {
        if (!class_exists(\Preflow\Core\Http\Csrf\CsrfMiddleware::class)) {
            return;
        }

        if (!$this->container->has(\Preflow\Core\Http\Session\SessionInterface::class)) {
            return;
        }

        $this->addMiddleware(new \Preflow\Core\Http\Csrf\CsrfMiddleware());
    }

    private function bootAuth(): void
    {
        if (!class_exists(\Preflow\Auth\AuthManager::class)) {
            return;
        }

        $authConfigPath = $this->basePath('config/auth.php');
        if (!file_exists($authConfigPath)) {
            return;
        }

        $authConfig = require $authConfigPath;

        // Password hasher
        $hasherClass = $authConfig['password_hasher'] ?? \Preflow\Auth\NativePasswordHasher::class;
        $hasher = new $hasherClass();
        $this->container->instance(\Preflow\Auth\PasswordHasherInterface::class, $hasher);

        // User providers
        $providers = [];
        foreach ($authConfig['providers'] ?? [] as $name => $providerConfig) {
            $providerClass = $providerConfig['class'];
            $modelClass = $providerConfig['model'] ?? null;

            if ($providerClass === \Preflow\Auth\DataManagerUserProvider::class
                && $this->container->has(\Preflow\Data\DataManager::class)) {
                $providers[$name] = new \Preflow\Auth\DataManagerUserProvider(
                    $this->container->get(\Preflow\Data\DataManager::class),
                    $modelClass,
                );
            }
        }

        // Guards
        $guards = [];
        foreach ($authConfig['guards'] ?? [] as $name => $guardConfig) {
            $guardClass = $guardConfig['class'];
            $provider = $providers[$guardConfig['provider'] ?? ''] ?? null;

            if ($provider === null) {
                continue;
            }

            $guards[$name] = match ($guardClass) {
                \Preflow\Auth\SessionGuard::class => new \Preflow\Auth\SessionGuard($provider, $hasher),
                \Preflow\Auth\TokenGuard::class => $this->container->has(\Preflow\Data\DataManager::class)
                    ? new \Preflow\Auth\TokenGuard(
                        $provider,
                        $this->container->get(\Preflow\Data\DataManager::class),
                    )
                    : null,
                default => null,
            };
        }

        $guards = array_filter($guards);
        $defaultGuard = $authConfig['default_guard'] ?? 'session';

        $authManager = new \Preflow\Auth\AuthManager($guards, $defaultGuard);
        $this->container->instance(\Preflow\Auth\AuthManager::class, $authManager);

        // Global middleware: resolve authenticated user on every request (no 401 — just populate)
        $defaultGuardInstance = $guards[$defaultGuard] ?? null;
        if ($defaultGuardInstance !== null) {
            $this->addMiddleware(new class($authManager, $defaultGuardInstance) implements \Psr\Http\Server\MiddlewareInterface {
                public function __construct(
                    private readonly \Preflow\Auth\AuthManager $authManager,
                    private readonly \Preflow\Auth\GuardInterface $guard,
                ) {}

                public function process(
                    \Psr\Http\Message\ServerRequestInterface $request,
                    \Psr\Http\Server\RequestHandlerInterface $handler,
                ): \Psr\Http\Message\ResponseInterface {
                    $user = $this->guard->user($request);
                    if ($user !== null) {
                        $this->authManager->setUser($user);
                        $request = $request->withAttribute(\Preflow\Auth\Authenticatable::class, $user);
                    }
                    return $handler->handle($request);
                }
            });
        }

        // Register middleware instances in container for route-level use
        $this->container->instance(
            \Preflow\Auth\Http\AuthMiddleware::class,
            new \Preflow\Auth\Http\AuthMiddleware($authManager),
        );
        $this->container->instance(
            \Preflow\Auth\Http\GuestMiddleware::class,
            new \Preflow\Auth\Http\GuestMiddleware($authManager),
        );

        // Template functions
        $session = $this->container->has(\Preflow\Core\Http\Session\SessionInterface::class)
            ? $this->container->get(\Preflow\Core\Http\Session\SessionInterface::class)
            : null;

        if ($this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
            $engine = $this->container->get(\Preflow\View\TemplateEngineInterface::class);
            $authProvider = new \Preflow\Auth\AuthExtensionProvider($authManager, $session);
            $this->registerExtensionProvider($engine, $authProvider);
        }
    }

    private function registerExtensionProvider(
        \Preflow\View\TemplateEngineInterface $engine,
        \Preflow\View\TemplateExtensionProvider $provider,
    ): void {
        foreach ($provider->getTemplateFunctions() as $function) {
            $engine->addFunction($function);
        }
        foreach ($provider->getTemplateGlobals() as $name => $value) {
            $engine->addGlobal($name, $value);
        }
    }

    /**
     * Auto-discover component classes in app/Components/.
     *
     * Scans recursively so components can be organized in group
     * directories (e.g. Shared/ThemeToggle, Player/PlayerShell).
     * A directory is a component when it contains a PHP file with
     * the same name as the directory.
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
        $this->scanComponentsRecursive($dir, 'App\\Components', $map);

        return $map;
    }

    /**
     * @param array<string, class-string> $map
     */
    private function scanComponentsRecursive(string $dir, string $namespace, array &$map): void
    {
        foreach (new \DirectoryIterator($dir) as $item) {
            if (!$item->isDir() || $item->isDot()) {
                continue;
            }

            $name = $item->getFilename();
            $phpFile = $item->getPathname() . '/' . $name . '.php';
            $childNamespace = $namespace . '\\' . $name;

            if (file_exists($phpFile)) {
                // This directory is a component (contains Name/Name.php)
                $class = $childNamespace . '\\' . $name;

                if (class_exists($class)) {
                    $map[$name] = $class;
                } elseif (class_exists($childNamespace)) {
                    // Flat namespace: App\Components\...\Name
                    $map[$name] = $childNamespace;
                }
            } else {
                // Not a component — recurse into group directory
                $this->scanComponentsRecursive($item->getPathname(), $childNamespace, $map);
            }
        }
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

            // Attach route parameters as request attributes
            foreach ($route->parameters as $name => $value) {
                $request = $request->withAttribute($name, $value);
            }

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
            if ($container->has(\Preflow\View\TemplateEngineInterface::class)) {
                $engine = $container->get(\Preflow\View\TemplateEngineInterface::class);
                $html = $engine->render($route->handler, [
                    'route' => (object) $route->parameters,
                    'request' => (object) [
                        'path' => $request->getUri()->getPath(),
                        'method' => $request->getMethod(),
                        'isHtmx' => $request->getHeaderLine('HX-Request') === 'true',
                        'query' => $request->getQueryParams(),
                    ],
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
