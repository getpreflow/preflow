<?php

declare(strict_types=1);

namespace Preflow\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Cache\CacheRegistry;
use Preflow\Core\Cache\DirectoryCache;
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
    private ?\Preflow\Core\Http\SecurityHeadersMiddleware $securityHeaders = null;
    private string $basePath;
    /** @var list<\Preflow\Auth\LazyUserProvider> */
    private array $lazyUserProviders = [];

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
        $this->container->instance(CacheRegistry::class, new CacheRegistry());
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
        $config = ['app' => $appConfig];

        // Responsive image presets (image.presets), read by the view layer and Folio's asset library.
        $imagePath = rtrim($basePath, '/') . '/config/image.php';
        if (file_exists($imagePath)) {
            $image = require $imagePath;
            $config['image'] = is_array($image) ? $image : [];
        }

        // View settings (view.pdf: temp dir, fonts, default font for PDFs).
        $viewPath = rtrim($basePath, '/') . '/config/view.php';
        if (file_exists($viewPath)) {
            $view = require $viewPath;
            $config['view'] = is_array($view) ? $view : [];
        }

        return new self(new Config($config), $basePath);
    }

    /** basePath($sub), anchored at the working directory when the base path is relative. */
    private function absolutePath(string $sub): string
    {
        $path = $this->basePath($sub);

        return str_starts_with($path, '/') ? $path : (getcwd() ?: '') . '/' . $path;
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

        // Wall-clock values (dates in content, scheduled publishing) use the app's
        // zone, in web requests and the CLI alike. Unknown names keep php.ini's zone.
        $timezone = $this->config->get('app.timezone');
        if (is_string($timezone) && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            date_default_timezone_set($timezone);
        }

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
        $this->bootSecurityHeaders($debug);
        $this->bootValidation();
        $this->bootHelpers();
        $this->bootPdf();
        $this->bootComponentLayer($debug, $secretKey, $collector);
        $this->bootForm();
        $this->bootRouting();
        $this->bootI18n();
        $this->bootNotify();
        $this->bootSession();
        $this->bootCsrf();
        $this->bootAuth();

        // Register user service providers
        $this->bootProviders();
        $this->bootLazyUserProviders();

        // Load user-defined middleware from config/middleware.php
        $this->bootMiddleware();

        // Error handler
        $renderer = $debug->isDebug()
            ? new DevErrorRenderer($collector, $this->nonceProvider())
            : new ProdErrorRenderer($this->nonceProvider());
        $errorHandler = new ErrorHandler($renderer);
        $this->container->instance(ErrorHandler::class, $errorHandler);

        // Default dispatchers if not set
        $this->ensureActionDispatcher();
        $this->ensureComponentRenderer();

        // Debug toolbar middleware — must be last so it wraps the full response
        if ($debug->isDebug() && $collector !== null && class_exists(\Preflow\DevTools\Http\DebugToolbarMiddleware::class)) {
            $this->addMiddleware(new \Preflow\DevTools\Http\DebugToolbarMiddleware($collector, $this->nonceProvider()));
        }

        $this->kernel = new Kernel(
            container: $this->container,
            router: $this->router ?? throw new \RuntimeException('No router configured. Install preflow/routing or set a router manually.'),
            pipeline: $this->pipeline,
            errorHandler: $errorHandler,
            actionDispatcher: $this->actionDispatcher,
            componentRenderer: $this->componentRenderer,
            collector: $collector,
            securityHeaders: $this->securityHeaders,
            componentEndpoint: $this->container->has('preflow.component_endpoint')
                ? fn (\Psr\Http\Message\ServerRequestInterface $r) => $this->container->get('preflow.component_endpoint')->handle($r)
                : null,
            cookies: $this->container->has(\Preflow\Core\Http\CookieQueue::class)
                ? $this->container->get(\Preflow\Core\Http\CookieQueue::class)
                : null,
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

        try {
            $response = $this->kernel->handle($request);
        } catch (\Throwable $e) {
            // Mails queued before the failure still go out (CLI, tests); run() flushes itself.
            if (!$this->emitting) {
                $this->flushDeferredNotifications();
            }
            throw $e;
        }
        if ($request->getMethod() === 'HEAD') {
            // Same status and headers as GET, no body (RFC 9110).
            $response = $response->withBody((new \Nyholm\Psr7\Factory\Psr17Factory())->createStream(''));
        }

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

        // In-process callers (CLI, tests) have no later emit step: send deferred
        // notifications now. run() flushes after the response has gone out instead.
        if (!$this->emitting) {
            $this->flushDeferredNotifications();
        }

        return $response;
    }

    private bool $emitting = false;

    private bool $notifyBooted = false;

    private ?\Closure $emitter = null;

    private ?\Closure $finisher = null;

    /**
     * Replace how run() emits the response and releases the client (tests).
     *
     * @internal
     *
     * @param \Closure(ResponseInterface): void $emit
     * @param \Closure(): void|null $finish
     */
    public function emitUsing(\Closure $emit, ?\Closure $finish = null): void
    {
        $this->emitter = $emit;
        $this->finisher = $finish;
    }

    private function flushDeferredNotifications(): void
    {
        // Only bound when config/notify.php exists, so this is a no-op without it.
        // The container's current Notifier is used, not a boot-time snapshot.
        if (!$this->notifyBooted) {
            return;
        }
        $this->container->get(\Preflow\Notify\Notifier::class)->flushDeferred();
    }

    /**
     * Release the client before slow work, as Symfony's Response::send() does:
     * PHP-FPM, then LiteSpeed LSAPI, then (outside the CLI) flush all buffers.
     */
    private function finishResponse(): void
    {
        if ($this->finisher !== null) {
            ($this->finisher)();

            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (!in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
            while (ob_get_level() > 0 && @ob_end_flush()) {
            }
            flush();
        }
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

            $this->emitting = true;
            $responseSent = false;
            try {
                $response = $this->handle($request);
                ($this->emitter ?? static fn (ResponseInterface $r) => (new Emitter())->emit($r))($response);
                $responseSent = true;
            } finally {
                $this->emitting = false;
                // Release the client only when a response went out; after a failure
                // the error output must still reach it. The queue is flushed either way,
                // and a flush error never masks the original exception.
                try {
                    if ($responseSent) {
                        $this->finishResponse();
                    }
                    $this->flushDeferredNotifications();
                } catch (\Throwable) {
                    error_log('Deferred notifications could not be flushed.');
                }
            }
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

        // Content locales are bound whenever preflow/data is installed (even
        // without config/data.php), so packages like Folio can always read them.
        $this->container->instance(\Preflow\Data\Locales::class, $this->contentLocales());

        // The migration registry is bound without config/data.php too, so
        // packages can register paths and steps regardless of data config.
        $migrations = new \Preflow\Data\Migration\MigrationRegistry();
        $migrations->addPath($this->basePath('migrations'));
        $migrations->addPath(dirname(__DIR__) . '/migrations');
        if (class_exists(\Preflow\Auth\Tokens\Tokens::class)) {
            $migrations->addPath(dirname((string) (new \ReflectionClass(\Preflow\Auth\Tokens\Tokens::class))->getFileName(), 3) . '/migrations');
        }
        $this->container->instance(\Preflow\Data\Migration\MigrationRegistry::class, $migrations);
        // Every record table packages sync from a type definition, for the conversion to find (data 0.42+).
        if (class_exists(\Preflow\Data\Schema\RecordTables::class)) {
            $this->container->instance(\Preflow\Data\Schema\RecordTables::class, new \Preflow\Data\Schema\RecordTables());
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
                    cacheDir: $this->basePath('storage/cache/data'),
                ),
                default => null,
            };

            if ($name === 'json') {
                $this->container->get(CacheRegistry::class)->register(new DirectoryCache(
                    'data.json',
                    'JSON storage query cache',
                    $this->absolutePath('storage/cache/data'),
                    pattern: 'json-*.json',
                ));
            }
        }

        $drivers = array_filter($drivers);

        $default = $dataConfig['default'] ?? 'sqlite';
        if (isset($drivers[$default])) {
            $drivers['default'] = $drivers[$default];
        }

        // Aliases name a purpose ('codes', 'throttle') and map it to a configured driver.
        foreach ($dataConfig['aliases'] ?? [] as $alias => $target) {
            if ($alias === 'default' || isset($drivers[$alias])) {
                throw new \RuntimeException("Storage alias [{$alias}] collides with a configured driver.");
            }
            if (!isset($drivers[$target])) {
                throw new \RuntimeException("Storage alias [{$alias}] points to [{$target}], which is not a configured driver.");
            }
            $drivers[$alias] = $drivers[$target];
        }

        // TypeRegistry for dynamic models
        $typeRegistry = null;
        $modelsPath = $dataConfig['models_path'] ?? $this->basePath('config/models');
        $locales = $this->container->get(\Preflow\Data\Locales::class);
        if (is_dir($modelsPath)) {
            $typeRegistry = new \Preflow\Data\TypeRegistry($modelsPath, $locales);
            $this->container->instance(\Preflow\Data\TypeRegistry::class, $typeRegistry);
        }

        // Validation — optional: wire if preflow/validation is installed
        $validatorFactory = null;
        if (class_exists(\Preflow\Validation\RuleFactory::class)) {
            $ruleFactory = new \Preflow\Validation\RuleFactory();

            // Auto-discover custom rules from app/Rules/
            $rulesPath = $this->basePath('app/Rules');
            if (is_dir($rulesPath)) {
                $ruleClasses = [];
                foreach (glob($rulesPath . '/*.php') as $file) {
                    $class = 'App\\Rules\\' . pathinfo($file, PATHINFO_FILENAME);
                    if (class_exists($class)) {
                        $ruleClasses[] = $class;
                    }
                }
                if ($ruleClasses !== []) {
                    $ruleFactory->discover($ruleClasses);
                }
            }

            $validatorFactory = new \Preflow\Validation\ValidatorFactory($ruleFactory);
            $this->container->instance(\Preflow\Validation\RuleFactory::class, $ruleFactory);
            $this->container->instance(\Preflow\Validation\ValidatorFactory::class, $validatorFactory);
        }

        $dataManager = new \Preflow\Data\DataManager($drivers, 'default', $typeRegistry, $validatorFactory);
        $this->container->instance(\Preflow\Data\DataManager::class, $dataManager);

        // Bound only with a DataManager (i.e. config/data.php present); it has nothing to store through otherwise.
        $this->container->instance(
            \Preflow\Core\Throttle\Throttle::class,
            new \Preflow\Core\Throttle\Throttle(
                $dataManager,
                (string) $this->config->get('app.key', \Preflow\Core\Throttle\Throttle::DEFAULT_KEY),
                isset($drivers['throttle']) ? 'throttle' : 'default',
                lockDir: $this->basePath('storage/locks'),
            ),
        );

        // Single-use tokens of preflow/auth (table auth_tokens), on storage "auth" when configured.
        if (class_exists(\Preflow\Auth\Tokens\Tokens::class)) {
            $this->container->instance(
                \Preflow\Auth\Tokens\Tokens::class,
                new \Preflow\Auth\Tokens\Tokens(
                    $dataManager,
                    isset($drivers['auth']) ? 'auth' : 'default',
                    lockDir: $this->basePath('storage/locks'),
                ),
            );
            // Remember-me tokens of session guards (table auth_remember), on the same storage.
            $this->container->instance(
                \Preflow\Auth\Remember\RememberMe::class,
                new \Preflow\Auth\Remember\RememberMe(
                    $dataManager,
                    isset($drivers['auth']) ? 'auth' : 'default',
                    lockDir: $this->basePath('storage/locks'),
                ),
            );
        }
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
            \Preflow\Data\Driver\MysqlDriver::connectionOptions(),
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

        // Nonce for hand-written <script>/<style> tags: nonce="{{ csp_nonce() }}"
        $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
            name: 'csp_nonce',
            callable: fn () => $assets->getNonce(),
            isSafe: true,
        ));
    }

    /**
     * Security headers + CSP. Piped right after the view layer (it needs the
     * request nonce) and before session/CSRF/auth middleware, so it wraps them.
     * Debug mode sends the policy as report-only so dev tooling keeps working.
     */
    /** @return \Closure(): ?string the request's CSP nonce, or null without preflow/view */
    private function nonceProvider(): \Closure
    {
        $container = $this->container;

        return static fn (): ?string => class_exists(\Preflow\View\AssetCollector::class)
            && $container->has(\Preflow\View\AssetCollector::class)
                ? $container->get(\Preflow\View\AssetCollector::class)->getNonce()
                : null;
    }

    private function bootSecurityHeaders(DebugLevel $debug): void
    {
        $config = [];
        $path = $this->basePath('config/security.php');
        if (file_exists($path)) {
            $config = (array) require $path;
        }

        $this->securityHeaders = new \Preflow\Core\Http\SecurityHeadersMiddleware($this->nonceProvider(), $config, $debug->isDebug());
        $this->addMiddleware($this->securityHeaders);
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
                ? $this->createBladeEngine($templateDirs, $assets, $debug)
                : null,
            default => throw new \RuntimeException("Unknown template engine: {$name}. Supported: twig, blade"),
        };
    }

    private function createBladeEngine(array $templateDirs, \Preflow\View\AssetCollector $assets, DebugLevel $debug): \Preflow\Blade\BladeEngine
    {
        // Passed explicitly so the engine and the registry use the same folder.
        $cachePath = $this->absolutePath('storage/cache/blade');

        $this->container->get(CacheRegistry::class)->register(new DirectoryCache(
            'blade',
            'Compiled Blade templates',
            $cachePath,
        ));

        return new \Preflow\Blade\BladeEngine(
            templateDirs: $templateDirs,
            assetCollector: $assets,
            debug: $debug->isDebug(),
            cachePath: $cachePath,
        );
    }

    private function bootValidation(): void
    {
        if (!class_exists(\Preflow\Validation\ValidationExtensionProvider::class)) {
            return;
        }

        if (!$this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
            return;
        }

        $engine = $this->container->get(\Preflow\View\TemplateEngineInterface::class);
        $validationProvider = new \Preflow\Validation\ValidationExtensionProvider();
        $this->container->instance(\Preflow\Validation\ValidationExtensionProvider::class, $validationProvider);
        $this->registerExtensionProvider($engine, $validationProvider);
    }

    private function bootHelpers(): void
    {
        if (!$this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
            return;
        }

        $engine = $this->container->get(\Preflow\View\TemplateEngineInterface::class);

        // Responsive image helper
        $responsiveImage = null;
        if (class_exists(\Preflow\View\ResponsiveImage::class)) {
            // Looked up at first use, so a service provider can bind its own transformer.
            $container = $this->container;
            $transformer = static fn (): \Preflow\View\ImageUrlTransformer => $container->has(\Preflow\View\ImageUrlTransformer::class)
                ? $container->get(\Preflow\View\ImageUrlTransformer::class)
                : new \Preflow\View\PathBasedTransformer();

            $presets = $this->config->get('image.presets', []);
            $responsiveImage = new \Preflow\View\ResponsiveImage($transformer, $presets);
            $this->container->instance(\Preflow\View\ResponsiveImage::class, $responsiveImage);
        }

        // Color + image template functions
        if (class_exists(\Preflow\Core\Helpers\HelpersExtensionProvider::class)) {
            $helpersProvider = new \Preflow\Core\Helpers\HelpersExtensionProvider($responsiveImage);
            $this->registerExtensionProvider($engine, $helpersProvider);
        }

        // QR and PDF building-block template functions
        if (class_exists(\Preflow\View\ViewExtensionProvider::class)) {
            $this->registerExtensionProvider($engine, new \Preflow\View\ViewExtensionProvider($this->container));
        }
    }

    /**
     * PDFs from templates. Both bindings are lazy, so a site's service
     * provider can bind its own PdfEngine; mPDF is bound only when installed,
     * looked up in Composer's metadata so its large class is not loaded on
     * every request.
     */
    private function bootPdf(): void
    {
        if (!class_exists(\Preflow\View\Pdf\PdfRenderer::class)
            || !$this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
            return;
        }

        $pdf = (array) $this->config->get('view.pdf', []);
        $config = new \Preflow\View\Pdf\PdfConfig(
            tempDir: $this->projectPath((string) ($pdf['temp_dir'] ?? 'storage/cache/pdf')),
            fonts: array_map(fn ($file): string => $this->projectPath((string) $file), (array) ($pdf['fonts'] ?? [])),
            defaultFont: (string) ($pdf['default_font'] ?? 'dejavusanscondensed'),
        );
        $this->container->instance(\Preflow\View\Pdf\PdfConfig::class, $config);

        if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('mpdf/mpdf')) {
            $this->container->singleton(
                \Preflow\View\Pdf\PdfEngine::class,
                static fn (): \Preflow\View\Pdf\PdfEngine => new \Preflow\View\Pdf\MpdfEngine($config),
            );
        }

        $this->container->singleton(
            \Preflow\View\Pdf\PdfRenderer::class,
            static fn (Container $c): \Preflow\View\Pdf\PdfRenderer => new \Preflow\View\Pdf\PdfRenderer(
                $c->get(\Preflow\View\TemplateEngineInterface::class),
                $c->bound(\Preflow\View\Pdf\PdfEngine::class) ? $c->get(\Preflow\View\Pdf\PdfEngine::class) : null,
                $config,
            ),
        );
    }

    /** A path from config: absolute as given, relative to the project root otherwise. */
    private function projectPath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->absolutePath($path);
    }

    private function bootForm(): void
    {
        if (!class_exists(\Preflow\Form\FormExtensionProvider::class)) {
            return;
        }

        if (!$this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
            return;
        }

        $engine = $this->container->get(\Preflow\View\TemplateEngineInterface::class);

        $formProvider = new \Preflow\Form\FormExtensionProvider(
            new \Preflow\Form\FieldRenderer(),
            new \Preflow\Form\GroupRenderer(),
            new \Preflow\Form\ModelIntrospector(),
        );

        // Wire in hypermedia driver if available
        if ($this->container->has(\Preflow\View\FormHypermediaDriver::class)) {
            $formProvider->setDriver($this->container->get(\Preflow\View\FormHypermediaDriver::class));
        }

        $this->container->instance(\Preflow\Form\FormExtensionProvider::class, $formProvider);
        $this->registerExtensionProvider($engine, $formProvider);
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
        $assets = $this->container->has(\Preflow\View\AssetCollector::class)
            ? $this->container->get(\Preflow\View\AssetCollector::class)
            : null;
        $renderer = new \Preflow\Components\ComponentRenderer($engine, $errorBoundary, $collector, $assets);
        $this->container->instance(\Preflow\Components\ComponentRenderer::class, $renderer);

        // Auto-discover components
        $componentMap = $this->discoverComponents();
        $this->container->instance(\Preflow\Components\ComponentMap::class, new \Preflow\Components\ComponentMap($componentMap));

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
            $this->container->instance(\Preflow\View\FormHypermediaDriver::class, $htmxDriver);
            $this->container->instance(\Preflow\Htmx\ComponentToken::class, $componentToken);

            // htmx itself: vendored and inlined (no external request). Included
            // always (default), on demand (when a template uses hd.*), or never.
            $assets = $this->container->get(\Preflow\View\AssetCollector::class);
            $htmxConfigPath = $this->basePath('config/htmx.php');
            $htmxConfig = file_exists($htmxConfigPath) ? (array) require $htmxConfigPath : [];
            $htmxAssets = new \Preflow\Htmx\HtmxAssets($assets, (string) ($htmxConfig['include'] ?? 'always'));
            $this->container->instance(\Preflow\Htmx\HtmxAssets::class, $htmxAssets);
            $htmxDriver->useAssets($htmxAssets); // form helpers (form_begin, inline validation) need htmx too
            if ($htmxAssets->mode() === 'always') {
                $htmxAssets->require();
            }

            // Register hd template functions
            $htmxProvider = new \Preflow\Htmx\HtmxExtensionProvider($htmxDriver, $componentToken, assets: $htmxAssets);
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
            templateExtension: $this->container->has(\Preflow\View\TemplateEngineInterface::class)
                ? $this->container->get(\Preflow\View\TemplateEngineInterface::class)->getTemplateExtension()
                : 'twig',
        );

        $this->setRouter($router);
    }

    /**
     * Content locales for translatable fields, from config/i18n.php (the same
     * file the i18n package reads); a single app.locale without it.
     */
    private function contentLocales(): \Preflow\Data\Locales
    {
        $path = $this->basePath('config/i18n.php');
        $config = file_exists($path) ? (array) require $path : [];
        $default = (string) ($config['default'] ?? $this->config->get('app.locale', 'en'));

        return new \Preflow\Data\Locales(
            array_values((array) ($config['available'] ?? [$default])),
            $default,
            isset($config['fallback']) ? (string) $config['fallback'] : null,
        );
    }

    private function bootI18n(): void
    {
        if (!class_exists(\Preflow\I18n\Translator::class)) {
            return;
        }

        // A translator is always booted: installed packages bring their texts (extra.preflow.translations).
        // Locale detection only with interface strings (lang/) and/or a locale config (a
        // content-only multilingual app may have config/i18n.php but no lang/ yet), so a
        // site with neither keeps its frontend unchanged.
        $langDir = $this->basePath('lang');
        $i18nPath = $this->basePath('config/i18n.php');
        $localized = is_dir($langDir) || file_exists($i18nPath);

        $i18nConfig = file_exists($i18nPath) ? (array) require $i18nPath : [];
        $locale = (string) ($i18nConfig['default'] ?? $this->config->get('app.locale', 'en'));
        $fallback = (string) ($i18nConfig['fallback'] ?? $locale);
        $available = array_values((array) ($i18nConfig['available'] ?? [$locale]));
        $strategy = (string) ($i18nConfig['url_strategy'] ?? 'prefix');

        $translator = new \Preflow\I18n\Translator($langDir, $locale, $fallback);
        $this->container->instance(\Preflow\I18n\Translator::class, $translator);
        if (class_exists(\Preflow\I18n\TranslationRegistry::class)) {
            $registry = \Preflow\I18n\TranslationRegistry::fromInstalled($this->basePath('vendor/composer/installed.json'));
            $registry->apply($translator);
            $this->container->instance(\Preflow\I18n\TranslationRegistry::class, $registry);
        }

        // t()/tc() and locale helpers
        if ($this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
            $engine = $this->container->get(\Preflow\View\TemplateEngineInterface::class);
            $translationProvider = new \Preflow\I18n\TranslationExtensionProvider($translator, $available, $locale);
            $this->registerExtensionProvider($engine, $translationProvider);
        }

        // Locale middleware. No cookie by default with the prefix strategy (the URL
        // carries the locale), so sites can truthfully claim "no cookies".
        if ($localized && class_exists(\Preflow\I18n\LocaleMiddleware::class)) {
            $this->addMiddleware(new \Preflow\I18n\LocaleMiddleware(
                $translator,
                $available,
                $locale,
                $strategy,
                (bool) ($i18nConfig['cookie'] ?? $strategy !== 'prefix'),
            ));
        }
    }

    private function bootNotify(): void
    {
        if (!class_exists(\Preflow\Notify\Notifier::class)) {
            return;
        }

        $notifyPath = $this->basePath('config/notify.php');
        if (!file_exists($notifyPath)) {
            return;
        }

        $notifyConfig = require $notifyPath;
        $factory = new \Preflow\Notify\TransportFactory(
            (array) ($notifyConfig['transports'] ?? []),
            $this->basePath(),
        );

        $this->container->instance(\Preflow\Notify\TransportFactory::class, $factory);
        $this->container->instance(
            \Preflow\Notify\Notifier::class,
            new \Preflow\Notify\Notifier(
                $factory,
                (array) ($notifyConfig['channels'] ?? []),
                // Resolved at failure time: a provider may bind the logger after boot.
                fn () => $this->container->has(\Psr\Log\LoggerInterface::class)
                    ? $this->container->get(\Psr\Log\LoggerInterface::class)
                    : null,
                filter_var($notifyConfig['defer'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
            ),
        );
        $this->notifyBooted = true;
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

        $authConfig = $this->authConfig();
        $sessionConfig = $authConfig['session'] ?? [];

        if ($sessionConfig === []) {
            return;
        }

        // session.paths limits sessions (and their cookie) to these path prefixes;
        // without it the session starts on every request.
        $paths = isset($sessionConfig['paths']) ? array_values((array) $sessionConfig['paths']) : null;
        unset($sessionConfig['paths']);

        $session = new \Preflow\Core\Http\Session\NativeSession($sessionConfig);
        if ($paths === null) {
            $session->start();
        }
        $this->container->instance(\Preflow\Core\Http\Session\SessionInterface::class, $session);

        $this->addMiddleware(new \Preflow\Core\Http\Session\SessionMiddleware($session, $paths));
    }

    /** @var array<string, mixed>|null config/auth.php, loaded once (it may hash passwords) */
    private ?array $authConfigCache = null;

    /** @return array<string, mixed> */
    private function authConfig(): array
    {
        return $this->authConfigCache ??= (array) require $this->basePath('config/auth.php');
    }

    private function bootCsrf(): void
    {
        if (!class_exists(\Preflow\Core\Http\Csrf\CsrfMiddleware::class)) {
            return;
        }

        if (!$this->container->has(\Preflow\Core\Http\Session\SessionInterface::class)) {
            return;
        }

        // A site's ExpiredSessionHandler is bound by its providers, which run after this:
        // the middleware looks it up when a POST's session turns out to have expired.
        $this->addMiddleware(new \Preflow\Core\Http\Csrf\CsrfMiddleware(
            expiredSessionHandler: new \Preflow\Core\Http\Csrf\ContainerExpiredSessionHandler($this->container),
        ));
    }

    /** Build the config-named user providers now that all services exist: a bad class fails during boot. */
    private function bootLazyUserProviders(): void
    {
        foreach ($this->lazyUserProviders as $provider) {
            $failure = $provider->bootResolve();
            if ($failure !== null && $this->container->has(\Psr\Log\LoggerInterface::class)) {
                $this->container->get(\Psr\Log\LoggerInterface::class)->warning('User provider unavailable, it resolves no users: ' . $failure->getMessage());
            }
        }
    }

    private function bootAuth(): void
    {
        if (!class_exists(\Preflow\Auth\AuthManager::class)) {
            return;
        }

        $authConfigPath = $this->basePath('config/auth.php');
        $registry = new \Preflow\Auth\Access\PermissionRegistry();
        $this->container->instance(\Preflow\Auth\Access\PermissionRegistry::class, $registry);
        if (!file_exists($authConfigPath)) {
            // No auth: can() allows everything, as there is nobody to restrict. Templates may still ask.
            $access = new \Preflow\Auth\Access\Access(null, $registry, false, []);
            $this->container->instance(\Preflow\Auth\Access\Access::class, $access);
            if ($this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
                $this->container->get(\Preflow\View\TemplateEngineInterface::class)->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                    name: 'can',
                    callable: fn (string $permission, ?object $subject = null): bool => $access->can($permission, $subject),
                ));
            }
            return;
        }

        $authConfig = $this->authConfig();

        // Password hasher
        $hasherClass = $authConfig['password_hasher'] ?? \Preflow\Auth\NativePasswordHasher::class;
        $hasher = new $hasherClass();
        $this->container->instance(\Preflow\Auth\PasswordHasherInterface::class, $hasher);

        // Record users from config/auth.php without a package that binds its own (Folio does, later).
        if (!$this->container->bound(\Preflow\Auth\Record\RecordUserProvider::class)) {
            $this->container->bind(\Preflow\Auth\Record\RecordUserProvider::class, \Preflow\Auth\Record\RecordUserProvider::factory());
        }

        // User providers
        $providers = [];
        foreach ($authConfig['providers'] ?? [] as $name => $providerConfig) {
            $providerClass = $providerConfig['class'];
            if ($providerClass === \Preflow\Auth\ChainUserProvider::class) {
                continue; // built below, once the providers it names exist
            }
            $modelClass = $providerConfig['model'] ?? null;

            if ($providerClass === \Preflow\Auth\DataManagerUserProvider::class
                && $this->container->has(\Preflow\Data\DataManager::class)) {
                $providers[$name] = new \Preflow\Auth\DataManagerUserProvider(
                    $this->container->get(\Preflow\Data\DataManager::class),
                    $modelClass,
                );
            }

            if ($providerClass === \Preflow\Auth\ConfigUserProvider::class) {
                $providers[$name] = new \Preflow\Auth\ConfigUserProvider($providerConfig['users'] ?? []);
            }

            // Any other class is built from the container with its config entry.
            if ($providerClass !== \Preflow\Auth\DataManagerUserProvider::class
                && $providerClass !== \Preflow\Auth\ConfigUserProvider::class) {
                // Lazily: it may need services that providers register later; bootLazyUserProviders() resolves it during boot.
                $container = $this->container;
                $provider = new \Preflow\Auth\LazyUserProvider(
                    (string) $name,
                    $providerClass,
                    static fn (): object => $container->make($providerClass, ['config' => $providerConfig]),
                );
                $this->lazyUserProviders[] = $provider;
                $providers[$name] = $provider;
            }
        }

        // Chains, after the providers they name (a chain may name an earlier chain).
        foreach ($authConfig['providers'] ?? [] as $name => $providerConfig) {
            if (($providerConfig['class'] ?? null) !== \Preflow\Auth\ChainUserProvider::class) {
                continue;
            }
            $members = [];
            foreach ((array) ($providerConfig['providers'] ?? []) as $member) {
                if (!isset($providers[$member])) {
                    throw new \RuntimeException("Provider \"{$name}\" (chain) names \"{$member}\", which is not a provider defined before it or is unavailable.");
                }
                $members[] = $providers[$member];
            }
            $providers[$name] = new \Preflow\Auth\ChainUserProvider($members);
        }

        // Guards. Each session guard keeps its own key (the default guard the classic
        // one), so a member signing out leaves staff signed in. Other classes are
        // built from the container with their provider.
        $defaultGuard = $authConfig['default_guard'] ?? 'session';
        $sessionKeys = [];
        foreach ($authConfig['guards'] ?? [] as $name => $guardConfig) {
            if (($guardConfig['class'] ?? null) === \Preflow\Auth\SessionGuard::class) {
                $sessionKeys[(string) $name] = $name === $defaultGuard ? '_auth_user_id' : '_auth_user_id.' . $name;
            }
        }
        // One queue for the session guards' cookies: ResolveUserMiddleware and the Kernel's error path apply it.
        $cookieQueue = new \Preflow\Core\Http\CookieQueue();
        $this->container->instance(\Preflow\Core\Http\CookieQueue::class, $cookieQueue);
        $guards = [];
        foreach ($authConfig['guards'] ?? [] as $name => $guardConfig) {
            $name = (string) $name;
            $guardClass = (string) ($guardConfig['class'] ?? '');
            $provider = $providers[$guardConfig['provider'] ?? ''] ?? null;

            if ($provider === null) {
                continue;
            }

            // 'remember' => ['cookie' => …, 'days' => …, 'path' => …] gives a session guard a
            // remember-me cookie (Secure when APP_SECURE is set); without it there is none.
            $remember = (array) ($guardConfig['remember'] ?? []);
            if ($remember !== [] && $guardClass === \Preflow\Auth\SessionGuard::class) {
                if (!$this->container->has(\Preflow\Auth\Remember\RememberMe::class)) {
                    throw new \RuntimeException("Guard \"{$name}\" remembers users: that needs preflow/data configured (config/data.php).");
                }
                $remember += ['cookie' => 'remember_' . $name, 'days' => 30, 'path' => '/'];
                $remember['secure'] = (bool) (getenv('APP_SECURE') ?: false);
            }

            $guard = match ($guardClass) {
                \Preflow\Auth\SessionGuard::class => new \Preflow\Auth\SessionGuard(
                    $provider,
                    $hasher,
                    $sessionKeys[$name],
                    array_values(array_diff_key($sessionKeys, [$name => true])),
                    $remember === [] ? null : $this->container->get(\Preflow\Auth\Remember\RememberMe::class),
                    $name,
                    $remember,
                    $cookieQueue,
                ),
                \Preflow\Auth\TokenGuard::class => $this->container->has(\Preflow\Data\DataManager::class)
                    ? new \Preflow\Auth\TokenGuard(
                        $provider,
                        $this->container->get(\Preflow\Data\DataManager::class),
                    )
                    : null,
                default => $this->container->make($guardClass, ['provider' => $provider]),
            };
            if ($guard !== null && !$guard instanceof \Preflow\Auth\GuardInterface) {
                throw new \RuntimeException("Guard \"{$name}\" ({$guardClass}) does not implement " . \Preflow\Auth\GuardInterface::class . '.');
            }
            if ($guard !== null) {
                $guards[$name] = $guard;
            }
        }

        $authManager = new \Preflow\Auth\AuthManager($guards, $defaultGuard);
        $this->container->instance(\Preflow\Auth\AuthManager::class, $authManager);

        // The permission question: staff/members per guard (config), default resolver until a package sets one.
        $populations = [];
        foreach ($authConfig['guards'] ?? [] as $name => $guardConfig) {
            if (is_string($guardConfig['population'] ?? null)) {
                $populations[(string) $name] = $guardConfig['population'];
            }
        }
        $access = new \Preflow\Auth\Access\Access($authManager, $registry, (bool) $this->config->get('app.debug', false), $populations);
        $this->container->instance(\Preflow\Auth\Access\Access::class, $access);

        // Bind the default guard's provider under UserProviderInterface so controllers can resolve it
        $defaultProviderName = $authConfig['guards'][$defaultGuard]['provider'] ?? null;
        if ($defaultProviderName !== null && isset($providers[$defaultProviderName])) {
            $this->container->instance(\Preflow\Auth\UserProviderInterface::class, $providers[$defaultProviderName]);
        }

        // Global middleware: who is signed in for this request (no 401, just populate).
        // Every guard is asked, the default first.
        if ($guards !== []) {
            $ordered = isset($guards[$defaultGuard]) ? [$defaultGuard => $guards[$defaultGuard]] + $guards : $guards;
            $this->addMiddleware(new \Preflow\Auth\Http\ResolveUserMiddleware($authManager, $ordered));
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
            $authProvider = new \Preflow\Auth\AuthExtensionProvider($authManager, $session, $access);
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
        return $assets->injectInto($html);
    }
}
