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
use Preflow\Core\Error\JsonErrorRenderer;
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

    /** @var list<\Closure(ServerRequestInterface): void> see onRequest() */
    private array $requestHooks = [];

    /** see runningInConsole(); null: decided by the PHP SAPI */
    private ?bool $console = null;

    /** see bootManifest() */
    private \Preflow\Core\Boot\BootManifest $manifest;

    /** @var array<string, mixed> config file results by name, see configFile() */
    private array $configFiles = [];

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
        $this->manifest = \Preflow\Core\Boot\BootManifest::off();
        $this->container->instance(\Preflow\Core\Boot\BootManifest::class, $this->manifest);
        $caches = new CacheRegistry();
        $caches->register(new \Preflow\Core\Cache\FileCache(
            'boot',
            'Boot manifest (preflow optimize)',
            \Preflow\Core\Boot\ManifestFile::path($this->absolutePath('')),
        ));
        $this->container->instance(CacheRegistry::class, $caches);
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

        $app = new self(new Config(), $basePath);
        $app->config->replace(self::configItems($app->configFile(...)));

        return $app;
    }

    /**
     * The Config items from the config files: app (config/app.php), image (responsive image
     * presets, read by the view layer and Folio's asset library) and view (view.pdf: temp dir,
     * fonts, default font for PDFs).
     *
     * @param \Closure(string): mixed $file configFile()
     * @return array<string, mixed>
     */
    private static function configItems(\Closure $file): array
    {
        $app = $file('app');
        $config = ['app' => $app ?? []];
        foreach (['image', 'view'] as $name) {
            $value = $file($name);
            if ($value !== null) {
                $config[$name] = is_array($value) ? $value : [];
            }
        }

        return $config;
    }

    /**
     * The result of config/{name}.php, null when there is no such file. Loaded once per
     * application; with a boot manifest, taken from it (see bootManifest()). Packages read the
     * app's config files through this, so a manifest boot needn't load them.
     */
    public function configFile(string $name): mixed
    {
        if (array_key_exists($name, $this->configFiles)) {
            return $this->configFiles[$name];
        }

        return $this->configFiles[$name] = $this->manifest->config($name, function () use ($name): mixed {
            $path = $this->basePath('config/' . $name . '.php');

            return is_file($path) ? require $path : null;
        });
    }

    /** Whether config/{name}.php exists (from the boot manifest when one is used). */
    public function hasConfigFile(string $name): bool
    {
        return $this->configFile($name) !== null;
    }

    /**
     * The boot manifest this boot uses (`preflow optimize`, {basePath}/bootstrap/cache/boot.php).
     * It is used only outside the console, with app.debug off, and while .env and
     * config/folio/extensions.json are unchanged (checked on every request); otherwise it is
     * off and every value is computed live. Packages take their catalogs from it through
     * BootManifest::remember().
     */
    public function bootManifest(): \Preflow\Core\Boot\BootManifest
    {
        return $this->manifest;
    }

    /**
     * Sets the boot manifest before boot() (ManifestBuilder records one; tests).
     *
     * @internal
     */
    public function useBootManifest(\Preflow\Core\Boot\BootManifest $manifest): void
    {
        $this->manifest = $manifest;
        $this->container->instance(\Preflow\Core\Boot\BootManifest::class, $manifest);
    }

    /**
     * Picks the boot manifest for this boot: a recording one gets the config files read so far;
     * outside the console, a current manifest file (with debug off) replaces the config.
     */
    private function resolveManifest(): void
    {
        if ($this->manifest->isRecording()) {
            $read = $this->configFiles;
            $this->configFiles = [];
            foreach ($read as $name => $value) {
                $this->configFiles[$name] = $this->manifest->config($name, static fn (): mixed => $value);
            }

            return;
        }
        if ($this->manifest->active() || $this->runningInConsole()) {
            return; // the console never reads the manifest
        }
        // Debug mode is decided live: create() loaded config/app.php with the request's environment, so
        // APP_DEBUG set by the web server or an edit of config/app.php on the server bypasses the manifest.
        if ((int) $this->config->get('app.debug', 0) !== 0) {
            return;
        }
        $data = \Preflow\Core\Boot\ManifestFile::load($this->absolutePath(''));
        if ($data === null || (int) ($data['config']['app']['debug'] ?? 0) !== 0) {
            return;
        }
        $this->useBootManifest(\Preflow\Core\Boot\BootManifest::fromData($data));
        $this->configFiles = [];
        $this->config->replace(self::configItems($this->configFile(...)));
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
     * Calls $hook with every request handle() receives, before any middleware and before
     * routing: the raw request, as it came in (no locale prefix stripped, no attributes).
     * A provider uses it to set up, on demand, what only some requests need, e.g. Folio
     * registers its admin routes on the first request under its prefix. Internal renders
     * (the SEO audit) go through handle() too, so they call the hooks as well.
     *
     * Hooks run in the order added and return nothing; one that throws fails the request
     * as a boot error would (it is not turned into an error page). Add hooks while booting
     * (in a provider's boot()).
     *
     * @param \Closure(ServerRequestInterface): void $hook
     */
    public function onRequest(\Closure $hook): void
    {
        $this->requestHooks[] = $hook;
    }

    /**
     * Whether the app serves the console (CLI or phpdbg) rather than web requests. Providers
     * set up at boot what commands need (Folio: its whole admin), since a command handles no
     * request. Tests that stand in for web requests in the CLI switch it off before boot().
     */
    public function runningInConsole(): bool
    {
        return $this->console ?? in_array(PHP_SAPI, ['cli', 'phpdbg'], true);
    }

    private ?string $pageCacheVerify = null;

    /**
     * Overrides page_cache.verify ('first', 'always', 'off'); set it before boot(). preflow/testing's
     * web apps set 'always', so a site's suite double-renders every page it stores.
     */
    public function setPageCacheVerify(string $mode): void
    {
        $this->pageCacheVerify = $mode;
    }

    /** Overrides runningInConsole() (tests that stand in for web requests); set it before boot(). */
    public function setRunningInConsole(bool $console): void
    {
        $this->console = $console;
    }

    /**
     * Boot the application — auto-discovers and wires packages.
     */
    public function boot(): void
    {
        $this->resolveManifest();
        $this->bootPageCache();

        $debug = DebugLevel::from((int) $this->config->get('app.debug', 0));
        $secretKey = $this->config->get('app.key', \Preflow\Core\Http\Csrf\StatelessCsrfToken::DEFAULT_KEY);

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

        // Fields kept from a form sent back after an expired session; read from the session when asked
        $container = $this->container;
        $this->container->instance(\Preflow\Core\Http\OldInput::class, new \Preflow\Core\Http\OldInput(
            static fn (): ?\Preflow\Core\Http\Session\SessionInterface => $container->bound(\Preflow\Core\Http\Session\SessionInterface::class)
                ? $container->get(\Preflow\Core\Http\Session\SessionInterface::class)
                : null,
        ));

        // Admin, API and endpoint prefixes that are not public pages; packages add theirs in their providers
        $nonPublic = new \Preflow\Core\Http\NonPublicPaths();
        $this->container->instance(\Preflow\Core\Http\NonPublicPaths::class, $nonPublic);

        // API prefixes (config/api.php): errors there are JSON, and SEO and GEO leave them alone
        $api = \Preflow\Core\Http\Api\ApiConfig::fromArray($this->configFile('api'));
        $this->container->instance(\Preflow\Core\Http\Api\ApiConfig::class, $api);
        foreach ($api->prefixes as $prefix) {
            $nonPublic->add($prefix);
        }

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
        $this->bootPageCacheServing($debug);
        $this->bootSession();
        $this->bootCsrf($debug, new \Preflow\Core\Http\Csrf\StatelessCsrfToken((string) $secretKey));
        $this->bootAuth();

        // Register user service providers
        $this->bootProviders();
        $this->bootLazyUserProviders();

        // Load user-defined middleware from config/middleware.php
        $this->bootMiddleware();

        // Redirects and the not-found list — after the site's middleware, so a site route wins
        $this->bootRedirects($debug);

        // SEO files, head tags and ETag — prepended, then GEO is prepended around it
        $this->bootSeo();

        // GEO Markdown views — prepended, so it runs before every other middleware
        $this->bootGeo($debug);

        // CORS for API paths — prepended last, so it is outermost: it wraps the page cache
        // (stored pages hold no CORS headers, hits get them) and answers preflights first.
        $cors = null;
        if ($api->corsOrigins !== []) {
            $cors = new \Preflow\Core\Http\Api\CorsMiddleware($api);
            $this->pipeline->prepend($cors);
        }

        // The SEO audit's page renderer (through the whole pipeline built above)
        $this->bootAudit();

        // Error handler
        $renderer = $debug->isDebug()
            ? new DevErrorRenderer($collector, $this->nonceProvider())
            : new ProdErrorRenderer($this->nonceProvider());
        // Internal renders (the SEO audit) get the visitor's error page, even in debug mode.
        $errorHandler = new ErrorHandler(
            $renderer,
            $debug->isDebug() ? new ProdErrorRenderer($this->nonceProvider()) : null,
            $api,
            new JsonErrorRenderer($debug->isDebug()),
        );
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
            cors: $cors,
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->kernel === null) {
            throw new \RuntimeException('Application not booted. Call boot() first.');
        }

        foreach ($this->requestHooks as $hook) {
            $hook($request);
        }

        $internal = \Preflow\Core\Http\InternalRender::is($request);
        if (!$internal) {
            // Work an earlier in-process request left for after its response (no run()) is dropped.
            $this->afterResponse = [];
            $this->rebuildsScheduled = false;
        }

        // Make request context available to components via DI
        $this->container->instance(RequestContext::class, new RequestContext(
            path: $request->getUri()->getPath(),
            method: $request->getMethod(),
            request: $request,
        ));

        // An internal render (the SEO audit) sends no mail: what it queues is not flushed here.
        $flush = !$this->emitting && !$internal;

        try {
            $response = $this->kernel->handle($request);
        } catch (\Throwable $e) {
            // What the request wrote may be written (part of it, or by another connection).
            $this->pageChanges?->flush();
            // Mails queued before the failure still go out (CLI, tests); run() flushes itself.
            if ($flush) {
                $this->flushDeferredNotifications();
            }
            throw $e;
        }
        if ($request->getMethod() === 'HEAD') {
            // Same status and headers as GET (with the GET length), no body (RFC 9110).
            $size = $response->getBody()->getSize();
            // Skipped like the Emitter does when stray output or an output handler would make it wrong.
            if ($size !== null && !$response->hasHeader('Content-Length') && !$response->hasHeader('Transfer-Encoding')
                && !in_array($response->getStatusCode(), [204, 304], true)
                && \Preflow\Core\Http\Emitter::outputIsUnbuffered()) {
                $response = $response->withHeader('Content-Length', (string) $size);
            }
            $response = $response->withBody((new \Nyholm\Psr7\Factory\Psr17Factory())->createStream(''));
        }

        // What the request wrote is invalidated before the response goes out: the visitor's next
        // request (the redirect after a POST) never gets a page showing the old state.
        $this->pageChanges?->flush();

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
        if ($flush) {
            $this->flushDeferredNotifications();
            if ($this->afterResponseInHandle) {
                $this->runAfterResponse();
            }
        }

        return $response;
    }

    /** @var list<\Closure(): void> work for after the response (afterResponse()) */
    private array $afterResponse = [];

    private bool $afterResponseInHandle = false;

    private bool $rebuildsScheduled = false;

    /**
     * Runs $work after the response of the current request has gone out: run() calls it once
     * the client is released (fastcgi_finish_request / litespeed_finish_request) and the
     * deferred mail is sent, in the order added. The page cache rebuilds there what the request
     * queued. A closure that throws is logged; the others still run. In-process callers of
     * handle() (commands, tests) have no "after the response": their work is dropped at the next
     * request, unless runAfterResponseInHandle() is on.
     *
     * @param \Closure(): void $work
     */
    public function afterResponse(\Closure $work): void
    {
        $this->afterResponse[] = $work;
    }

    /**
     * Runs the work added with afterResponse() now (what run() does after the response).
     *
     * @internal run(), and tests through runAfterResponseInHandle()
     */
    public function runAfterResponse(): void
    {
        while ($this->afterResponse !== []) {
            $work = array_shift($this->afterResponse);
            try {
                $work();
            } catch (\Throwable $e) {
                error_log('After-response work failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * A test seam: handle() runs the after-response work itself at its end, as run() does after
     * emitting (a top-level request only, not an internal render). Set it after boot() or before.
     *
     * @internal
     */
    public function runAfterResponseInHandle(bool $on = true): void
    {
        $this->afterResponseInHandle = $on;
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
                ($this->emitter ?? fn (ResponseInterface $r) => (new Emitter())->emit($r, $request))($response);
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
                // The page cache's rebuilds and other work queued for after the response.
                if ($responseSent) {
                    $this->runAfterResponse();
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

        $dataConfig = $this->configFile('data');
        if ($dataConfig === null) {
            return;
        }
        $dataConfig = is_array($dataConfig) ? $dataConfig : [];
        $drivers = [];

        foreach ($dataConfig['drivers'] ?? [] as $name => $driverConfig) {
            $drivers[$name] = match ($name) {
                'sqlite' => $this->createSqliteDriver($driverConfig, $collector),
                'mysql' => $this->createMysqlDriver($driverConfig, $collector),
                'json' => $jsonDriver = new \Preflow\Data\Driver\JsonFileDriver(
                    $driverConfig['path'] ?? $this->basePath('storage/data'),
                    cacheDir: $this->dataCachePath(),
                ),
                default => null,
            };

            if ($name === 'json') {
                $this->container->get(CacheRegistry::class)->register(new DirectoryCache(
                    'data.json',
                    'JSON storage query cache',
                    $this->dataCachePath(absolute: true),
                    // Every path key's .json caches and leftover .tmp-* files, plus the .gen files of
                    // other data paths (old test runs, moved sites). This app's own .gen files stay:
                    // they guard a cache write racing a save.
                    pattern: 'json-*',
                    exclude: 'json-*-' . $jsonDriver->pathKey() . '.gen',
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
        // Model classes marked #[Entity(type: …)] are types too, so the registry is there for them alone.
        // A relative entry is under the app, not the working directory (public/ on a web request).
        $modelPaths = isset($dataConfig['model_paths'])
            ? array_map(
                fn (string $path): string => str_starts_with($path, '/') ? $path : $this->absolutePath($path),
                array_values(array_filter((array) $dataConfig['model_paths'], 'is_string')),
            )
            : [$this->basePath('app/Models')];
        $classes = class_exists(\Preflow\Data\ModelClasses::class)
            ? (new \Preflow\Data\ModelClasses($modelPaths, $this->manifest))->all()
            : [];
        if (is_dir($modelsPath) || $classes !== []) {
            $typeRegistry = new \Preflow\Data\TypeRegistry($modelsPath, $locales, $classes);
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
        if ($this->pageChanges !== null) {
            $dataManager->observe(new \Preflow\Core\PageCache\DataLayerObserver(
                $this->container->get(\Preflow\Core\PageCache\DependencyRecorder::class),
                $this->pageChanges,
            ));
        } elseif ($this->pageCacheMarker?->exists() === true) {
            // Off, with pages from when it was on: the first write removes the marker (switched on
            // again, the cache starts empty) and the observer goes, so later queries cost nothing.
            $dataManager->observe(new \Preflow\Core\PageCache\DisabledWritesObserver(
                $this->pageCacheMarker,
                static function () use ($dataManager): void {
                    $dataManager->observe(null);
                },
            ));
        }

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
        $driver = new \Preflow\Data\Driver\SqliteDriver(static fn (): \PDO => new \PDO($dsn), $collector);
        $this->container->singleton(\PDO::class, static fn (): \PDO => $driver->pdo());
        return $driver;
    }

    private function createMysqlDriver(array $config, ?DebugCollector $collector = null): \Preflow\Data\Driver\MysqlDriver
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'] ?? getenv('DB_HOST') ?: '127.0.0.1',
            $config['port'] ?? getenv('DB_PORT') ?: '3306',
            $config['database'] ?? getenv('DB_NAME') ?: '',
        );
        $user = $config['username'] ?? getenv('DB_USER') ?: 'root';
        $pass = $config['password'] ?? getenv('DB_PASS') ?: '';
        // Opened on the first query: a request that reads no data never connects.
        $driver = new \Preflow\Data\Driver\MysqlDriver(
            static fn (): \PDO => new \PDO($dsn, $user, $pass, \Preflow\Data\Driver\MysqlDriver::connectionOptions()),
            $collector,
        );
        $this->container->singleton(\PDO::class, static fn (): \PDO => $driver->pdo());
        return $driver;
    }

    private function bootViewLayer(DebugLevel $debug): void
    {
        $nonce = new \Preflow\View\NonceGenerator();
        $assets = new \Preflow\View\AssetCollector(
            $nonce,
            isProd: !$debug->isDebug(),
            cachePath: $this->assetsCachePath(),
        );
        $this->container->instance(\Preflow\View\AssetCollector::class, $assets);
        $this->container->instance(\Preflow\View\NonceGenerator::class, $nonce);

        // Markdown declared with {% apply geo %} / @geo; records only while GEO captures
        $geo = new \Preflow\View\Geo\GeoCollector();
        $this->container->instance(\Preflow\View\Geo\GeoCollector::class, $geo);
        // Packages with request-scoped state add a wrapper here (bootGeo adds the core one)
        $this->container->instance(\Preflow\View\Geo\RenderScopes::class, new \Preflow\View\Geo\RenderScopes());
        // GEO settings and llms.txt's pages: here, so providers can read the one and add to the other
        $this->container->instance(\Preflow\View\Geo\GeoConfig::class, $this->viewConfig(\Preflow\View\Geo\GeoConfig::class, 'geo'));
        // The public pages the sitemap and llms.txt both list
        $publicPages = new \Preflow\View\Seo\PublicPages();
        $this->container->instance(\Preflow\View\Seo\PublicPages::class, $publicPages);
        $this->container->instance(\Preflow\View\Geo\GeoEntries::class, new \Preflow\View\Geo\GeoEntries($publicPages));
        // The SEO audit's checks: here, so providers can add theirs. The built-in ones are made when an audit
        // first reads the checks (bootAudit binds the renderer), so web requests don't build them.
        $this->container->instance(\Preflow\View\Audit\AuditRegistry::class, new \Preflow\View\Audit\AuditRegistry(
            function (\Preflow\View\Audit\AuditRegistry $registry) use ($publicPages): bool {
                if (!$this->container->bound(\Preflow\View\Audit\PageRenderer::class)) {
                    return false; // read before bootAudit: asked again on the next read
                }
                \Preflow\View\Audit\Checks\BuiltInChecks::register(
                    $registry,
                    $this->container->get(\Preflow\View\Audit\PageRenderer::class),
                    $publicPages,
                    $this->basePath('public'),
                    $this->container->get(\Preflow\View\Seo\SeoConfig::class),
                    $this->container->get(\Preflow\View\Geo\GeoConfig::class),
                    $this->container->get(\Preflow\View\Seo\SeoSite::class),
                    $this->container->get(\Preflow\View\Seo\SchemaRegistry::class),
                );

                return true;
            },
        ));
        // SEO settings, the page meta templates and controllers fill, and what sites register (JSON-LD builders, defaults)
        $seoConfig = $this->viewConfig(\Preflow\View\Seo\SeoConfig::class, 'seo');
        // A typo in a template's seo({…}) is ignored; in debug mode the log names it.
        $seoContext = new \Preflow\View\Seo\SeoContext($debug->isDebug()
            ? static function (array $keys): void {
                error_log('seo(): unknown key(s) ignored: ' . implode(', ', $keys));
            }
            : null);
        $this->container->instance(\Preflow\View\Seo\SeoConfig::class, $seoConfig);
        $this->container->instance(\Preflow\View\Seo\SeoContext::class, $seoContext);
        $this->container->instance(\Preflow\View\Seo\SchemaRegistry::class, new \Preflow\View\Seo\SchemaRegistry());
        $this->container->instance(\Preflow\View\Seo\SeoSite::class, new \Preflow\View\Seo\SeoSite());

        $pagesDir = $this->basePath('app/pages');
        $templateDirs = is_dir($pagesDir) ? [$pagesDir] : [];

        // Also register the project root so that templates in app/pages can
        // extend layout files via "templates/_base.twig" etc.
        $projectRoot = $this->basePath();
        if (!in_array($projectRoot, $templateDirs, true)) {
            $templateDirs[] = $projectRoot;
        }

        $engineName = $this->config->get('app.engine', 'twig');
        // seo() in templates is a no-op while SEO is off
        $engine = $this->createTemplateEngine($engineName, $templateDirs, $assets, $debug, $geo, $seoConfig->enabled() ? $seoContext : null);

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
     * GeoConfig or SeoConfig from config/{name}.php (through configFile()); the defaults without one.
     *
     * @template T of \Preflow\View\Geo\GeoConfig|\Preflow\View\Seo\SeoConfig
     * @param class-string<T> $class
     * @return T
     */
    private function viewConfig(string $class, string $name): object
    {
        $config = $this->configFile($name);

        return $config === null ? new $class() : $class::fromArray((array) $config);
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
        $config = (array) ($this->configFile('security') ?? []);

        $this->securityHeaders = new \Preflow\Core\Http\SecurityHeadersMiddleware($this->nonceProvider(), $config, $debug->isDebug());
        $this->addMiddleware($this->securityHeaders);
    }

    private function createTemplateEngine(
        string $name,
        array $templateDirs,
        \Preflow\View\AssetCollector $assets,
        DebugLevel $debug,
        ?\Preflow\View\Geo\GeoCollector $geo = null,
        ?\Preflow\View\Seo\SeoContext $seo = null,
    ): ?\Preflow\View\TemplateEngineInterface {
        return match ($name) {
            'twig' => class_exists(\Preflow\Twig\TwigEngine::class)
                ? new \Preflow\Twig\TwigEngine(
                    templateDirs: $templateDirs,
                    assetCollector: $assets,
                    debug: $debug->isDebug(),
                    cachePath: $this->twigCachePath(),
                    geoCollector: $geo,
                    seoContext: $seo,
                    liveParts: $this->container->get(\Preflow\Core\PageCache\LiveParts::class),
                    pageCache: $this->container->get(\Preflow\Core\PageCache\PageCache::class),
                )
                : null,
            'blade' => class_exists(\Preflow\Blade\BladeEngine::class)
                ? $this->createBladeEngine($templateDirs, $assets, $debug, $geo, $seo)
                : null,
            default => throw new \RuntimeException("Unknown template engine: {$name}. Supported: twig, blade"),
        };
    }

    /**
     * The page cache's settings (config/page_cache.php, PageCacheConfig) and its store in the
     * CacheRegistry (key "pages", storage/cache/pages, per release). Serving and storing pages
     * is wired separately; the release key is computed only when the cache is cleared.
     */
    private function bootPageCache(): void
    {
        $file = $this->configFile('page_cache');
        $config = \Preflow\Core\PageCache\PageCacheConfig::fromArray(
            is_array($file) ? $file : [],
            is_string($url = $this->config->get('app.url')) ? $url : null,
        );
        if ($config->enabled) {
            $config = $config->withSignedInCookies($this->signedInCookies());
        }
        if ($this->pageCacheVerify !== null) {
            $config = $config->withVerify($this->pageCacheVerify);
        }
        $this->container->instance(\Preflow\Core\PageCache\PageCacheConfig::class, $config);
        $base = $this->absolutePath('');
        $root = $this->absolutePath('storage/cache/pages');
        $this->pageCacheSource = new \Preflow\Core\PageCache\PageCacheSource(
            $root,
            static fn (): string => \Preflow\Core\PageCache\PageStore::releaseKey($base),
        );
        $this->container->get(CacheRegistry::class)->register($this->pageCacheSource);
        $this->pageCacheMarker = new \Preflow\Core\PageCache\EnabledMarker($root);

        // The CSP nonce, the CSRF token and a site's own values (registered in providers):
        // replaced by placeholders in stored pages, filled in on every hit.
        $values = new \Preflow\Core\PageCache\PerRequestValues((string) $this->config->get('app.key', \Preflow\Core\Http\Csrf\StatelessCsrfToken::DEFAULT_KEY));
        $nonce = $this->nonceProvider();
        $values->register('nonce', static fn (): ?string => $nonce());
        $container = $this->container;
        $values->register('csrf', static function () use ($container): ?string {
            $stateless = $container->bound(\Preflow\Core\Http\Csrf\StatelessCsrfToken::class)
                ? $container->get(\Preflow\Core\Http\Csrf\StatelessCsrfToken::class)
                : null;

            return $stateless?->enabled() === true ? $stateless->issue() : null;
        });
        $this->container->instance(\Preflow\Core\PageCache\PerRequestValues::class, $values);

        // What pages read while rendering (recorded only while a render records) and what writes
        // changed. Off, or without pdo_sqlite (no index, nothing cached): no observer, no change
        // log, no IO; PageCache::changed() does nothing. On, writes invalidate from the CLI too.
        $this->container->instance(\Preflow\Core\PageCache\DependencyRecorder::class, new \Preflow\Core\PageCache\DependencyRecorder());
        $this->pageChanges = null;
        if ($config->enabled && extension_loaded('pdo_sqlite')) {
            $container = $this->container;
            $this->container->singleton(
                \Preflow\Core\PageCache\PageStore::class,
                static fn (): \Preflow\Core\PageCache\PageStore => new \Preflow\Core\PageCache\PageStore($root, \Preflow\Core\PageCache\PageStore::releaseKey($base)),
            );
            // Rebuilds render through the whole pipeline, where the cache serves and stores: not
            // in debug mode (the cache is off), else on the web and, for rebuilds only, the console.
            $rebuilds = !DebugLevel::from((int) $this->config->get('app.debug', 0))->isDebug();
            $origin = $this->config->get('app.url');
            $this->container->singleton(
                \Preflow\Core\PageCache\Refresher::class,
                fn (): \Preflow\Core\PageCache\Refresher => new \Preflow\Core\PageCache\Refresher(
                    $container->get(\Preflow\Core\PageCache\PageStore::class),
                    $config,
                    rebuild: $rebuilds ? $this->renderRebuild(...) : null,
                    onQueue: $this->scheduleRebuilds(...),
                    log: $this->pageCacheLogger(),
                    origin: is_string($origin) && $origin !== '' ? $origin : null,
                ),
            );
            $this->pageChanges = new \Preflow\Core\PageCache\ChangeLog(
                static function (array $tags) use ($container): void {
                    $container->get(\Preflow\Core\PageCache\Refresher::class)->invalidate($tags);
                },
                // A command (a cron import) flushes as it goes too: a killed run loses little.
                flushEvery: $this->runningInConsole() ? 30.0 : null,
                flushAbove: $this->runningInConsole() ? 5000 : 0,
            );
            $this->container->instance(\Preflow\Core\PageCache\ChangeLog::class, $this->pageChanges);
        }
        $container = $this->container;
        $this->container->instance(\Preflow\Core\PageCache\PageCache::class, new \Preflow\Core\PageCache\PageCache(
            $this->pageChanges,
            static fn (string $storage): string => $container->bound(\Preflow\Data\DataManager::class)
                ? $container->get(\Preflow\Data\DataManager::class)->reportedStorage($storage)
                : $storage,
            // Off: a write reported by hand still means the cache starts empty when switched on again.
            $this->pageChanges === null ? $this->pageCacheMarker : null,
            // The freshness dial ({% freshness %}, @freshness, #[Freshness]) and page expiry.
            $this->container->get(\Preflow\Core\PageCache\DependencyRecorder::class),
        ));

        // Live parts ({% live %}, @live, #[Live] components): placeholders signed with app.key
        // while a page renders for the cache, filled in for each visitor. Off, they render in place.
        $this->container->instance(\Preflow\Core\PageCache\LiveParts::class, new \Preflow\Core\PageCache\LiveParts(
            new \Preflow\Core\PageCache\LivePlaceholder((string) $this->config->get('app.key', \Preflow\Core\Http\Csrf\StatelessCsrfToken::DEFAULT_KEY)),
            $this->container->get(\Preflow\Core\PageCache\PageCache::class),
            $this->container->get(\Preflow\Core\PageCache\DependencyRecorder::class),
            $this->pageCacheLogger(),
        ));
    }

    /**
     * Pages were queued for rebuilding in this request: the work runs once after its response,
     * within page_cache.rebuild_budget (what is left is the cron's, `preflow pages:work`).
     */
    private function scheduleRebuilds(): void
    {
        if ($this->rebuildsScheduled) {
            return;
        }
        $this->rebuildsScheduled = true;
        $container = $this->container;
        $this->afterResponse(function () use ($container): void {
            // One pass per request: what the rebuilds themselves queue waits for the next request or the cron.
            try {
                $budget = $container->get(\Preflow\Core\PageCache\PageCacheConfig::class)->rebuildBudget;
                $container->get(\Preflow\Core\PageCache\Refresher::class)->work($budget);
            } finally {
                $this->rebuildsScheduled = false;
            }
        });
    }

    /** @var \Preflow\View\Geo\RenderScopes|null the scopes around a rebuild, made on the first one */
    private ?object $rebuildScopes = null;

    /**
     * Renders a rebuild request (Rebuild::request()) through the whole app, as an anonymous
     * visitor and starting from clean request-scoped state each time, as the SEO audit's renders
     * do: the request context, the hidden session data, nobody signed in, the translator locale,
     * the SEO context and the content locale (the render scopes), the assets boot registered,
     * and no recording of the page around it (DependencyRecorder::outside(): the page cache
     * middleware opens the rebuild's own). So one page's state never reaches the next.
     *
     * PHP's superglobals are an anonymous visitor's too (AnonymousGlobals, as verify's): after a
     * response, they are still those of the request that wrote (its cookies, address, session).
     * With a PHP session open the rebuild is refused without rendering.
     */
    private function renderRebuild(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->container->bound(\Preflow\Core\PageCache\PageCacheMiddleware::class)) {
            throw new \LogicException('The page cache does not serve in this app (debug mode?).');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return new \Nyholm\Psr7\Response(200, [\Preflow\Core\PageCache\PageCacheMiddleware::SKIP_HEADER => 'session']);
        }
        $render = fn (): ResponseInterface => $this->handle($request);
        if ($this->container->bound(\Preflow\View\Geo\RenderScopes::class)) {
            $scopes = $this->rebuildScopes ??= $this->internalRenderScopes();
            $scoped = static fn (): ResponseInterface => $scopes->run($request, $render);
        } else {
            $anonymous = $this->anonymousRenderScope();
            $scoped = static fn (): ResponseInterface => $anonymous($request, $render);
        }
        $assets = $this->container->has(\Preflow\View\AssetCollector::class) ? $this->container->get(\Preflow\View\AssetCollector::class) : null;
        $clean = static fn (): ResponseInterface => $assets !== null ? $assets->restoring($scoped) : $scoped();

        $recorder = $this->container->get(\Preflow\Core\PageCache\DependencyRecorder::class);

        return \Preflow\Core\PageCache\AnonymousGlobals::run(
            '127.0.0.1',
            static fn (): ResponseInterface => $recorder->outside($clean),
            $request->getQueryParams(),
        );
    }

    /** @return \Closure(string): void the site's logger (a warning), error_log() without one */
    private function pageCacheLogger(): \Closure
    {
        $container = $this->container;

        return static function (string $message) use ($container): void {
            if ($container->has(\Psr\Log\LoggerInterface::class)) {
                $container->get(\Psr\Log\LoggerInterface::class)->warning($message);

                return;
            }
            error_log($message);
        };
    }

    /** The page cache's change log, when the cache is on (bootPageCache()). */
    private ?\Preflow\Core\PageCache\ChangeLog $pageChanges = null;

    private ?\Preflow\Core\PageCache\PageCacheSource $pageCacheSource = null;

    private ?\Preflow\Core\PageCache\EnabledMarker $pageCacheMarker = null;

    /**
     * The cookies that mean "signed in" besides page_cache.signed_in_cookies: the auth marker
     * (preflow_auth) and every session guard's remember-me cookie (config/auth.php).
     *
     * @return list<string>
     */
    private function signedInCookies(): array
    {
        $names = [\Preflow\Core\PageCache\PageCacheConfig::SIGNED_IN_MARKER];
        if (!$this->hasConfigFile('auth')) {
            return $names;
        }
        foreach ((array) ($this->authConfig()['guards'] ?? []) as $name => $guard) {
            $remember = is_array($guard) ? ($guard['remember'] ?? []) : [];
            if (is_array($remember) && $remember !== []) {
                $names[] = (string) ($remember['cookie'] ?? 'remember_' . $name);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Serving pages from the cache (PageCacheMiddleware): right after the locale middleware (the
     * key holds the locale it resolved) and before the session middleware (a hit opens no
     * session). Only when page_cache.enabled, pdo_sqlite is there and app.debug is off. In the
     * console it is there for rebuilds only (`preflow pages:work`): every other request passes.
     * A stale page served queues its rebuild (Refresher::stale()); a pending invalidation is
     * applied before a hit (Refresher::applyPending()).
     */
    private function bootPageCacheServing(DebugLevel $debug): void
    {
        $config = $this->container->get(\Preflow\Core\PageCache\PageCacheConfig::class);
        if (!$config->enabled || $this->pageChanges === null || $debug->isDebug()) {
            return;
        }
        $container = $this->container;
        $source = $this->pageCacheSource;
        $logger = $this->pageCacheLogger();
        $middleware = new \Preflow\Core\PageCache\PageCacheMiddleware(
            config: $config,
            store: static fn (): \Preflow\Core\PageCache\PageStore => $container->get(\Preflow\Core\PageCache\PageStore::class),
            cache: $container->get(\Preflow\Core\PageCache\PageCache::class),
            recorder: $container->get(\Preflow\Core\PageCache\DependencyRecorder::class),
            values: $container->get(\Preflow\Core\PageCache\PerRequestValues::class),
            marker: $this->pageCacheMarker ?? throw new \LogicException('bootPageCache() runs first.'),
            clearAll: static function () use ($source): void {
                $source?->clear();
            },
            localePrefix: static fn (): string => class_exists(\Preflow\I18n\LocaleMiddleware::class) && $container->bound(\Preflow\I18n\LocaleMiddleware::class)
                ? $container->get(\Preflow\I18n\LocaleMiddleware::class)->urlPrefix()
                : '',
            signedIn: static fn (): bool => class_exists(\Preflow\Auth\AuthManager::class) && $container->bound(\Preflow\Auth\AuthManager::class)
                && $container->get(\Preflow\Auth\AuthManager::class)->users() !== [],
            sessionStarted: static fn (): bool => $container->bound(\Preflow\Core\Http\Session\SessionInterface::class)
                && $container->get(\Preflow\Core\Http\Session\SessionInterface::class)->isStarted(),
            nonPublic: static fn (string $path): bool => $container->get(\Preflow\Core\Http\NonPublicPaths::class)->matches($path),
            onStale: static function (\Preflow\Core\PageCache\PageKey $key) use ($container): void {
                $container->get(\Preflow\Core\PageCache\Refresher::class)->stale($key);
            },
            log: $logger,
            live: $container->get(\Preflow\Core\PageCache\LiveParts::class),
            verify: $config->verify,
            visitorScope: static function (ServerRequestInterface $visitor, \Closure $render) use ($container): ResponseInterface {
                $context = $container->bound(RequestContext::class) ? $container->get(RequestContext::class) : null;
                $container->instance(RequestContext::class, new RequestContext($visitor->getUri()->getPath(), $visitor->getMethod(), $visitor));
                try {
                    // Both renders start from the assets boot registered, so the second prints the same.
                    return $container->has(\Preflow\View\AssetCollector::class)
                        ? $container->get(\Preflow\View\AssetCollector::class)->restoring($render)
                        : $render();
                } finally {
                    if ($context !== null) {
                        $container->instance(RequestContext::class, $context);
                    }
                }
            },
            applyPending: static fn (): bool => $container->get(\Preflow\Core\PageCache\Refresher::class)->applyPending(),
            rebuildOnly: $this->runningInConsole(),
        );
        $this->container->instance(\Preflow\Core\PageCache\PageCacheMiddleware::class, $middleware);
        $this->addMiddleware($middleware);
    }

    /** Minified CSS and JS live in storage/cache/assets, keyed by content. */
    private function assetsCachePath(): string
    {
        $cachePath = $this->absolutePath('storage/cache');

        $this->container->get(CacheRegistry::class)->register(new DirectoryCache(
            'assets',
            'Minified CSS and JS',
            $cachePath . '/assets',
        ));

        return $cachePath;
    }

    /**
     * Where the JSON storage keeps its query caches: storage/cache/data, or DATA_CACHE_PATH when
     * set (a test scaffold with its own DATA_PATH sets it under the same temp root). A relative
     * DATA_CACHE_PATH is resolved against the application's base path.
     */
    private function dataCachePath(bool $absolute = false): string
    {
        $env = $_ENV['DATA_CACHE_PATH'] ?? getenv('DATA_CACHE_PATH');
        if (is_string($env) && $env !== '') {
            $env = rtrim($env, '/') ?: '/';

            return str_starts_with($env, '/') ? $env : $this->absolutePath($env);
        }

        return $absolute ? $this->absolutePath('storage/cache/data') : $this->basePath('storage/cache/data');
    }

    /** The decoded composer installed.json lives in storage/cache/composer. */
    private function composerCachePath(): string
    {
        $path = $this->absolutePath('storage/cache/composer');

        $this->container->get(CacheRegistry::class)->register(new DirectoryCache(
            'composer',
            'Decoded composer installed.json',
            $path,
        ));

        return $path;
    }

    /**
     * Compiled Twig templates live in storage/cache/twig/{packages key}; auto_reload recompiles an
     * edited source. The subfolder follows composer's installed.json (mtime and size), so after an
     * install or update templates are compiled anew with the extensions' current function flags,
     * even when the release folder stays the same. cache:clear empties the parent, old keys too.
     */
    private function twigCachePath(): string
    {
        $cachePath = $this->absolutePath('storage/cache/twig');

        $this->container->get(CacheRegistry::class)->register(new DirectoryCache(
            'twig',
            'Compiled Twig templates',
            $cachePath,
        ));

        return $cachePath . '/' . self::packagesKey($this->absolutePath('vendor/composer/installed.json'));
    }

    /** A short key for the installed package set: changes with every composer install or update. */
    private static function packagesKey(string $installedJson): string
    {
        clearstatcache(true, $installedJson);
        $mtime = @filemtime($installedJson);
        $size = @filesize($installedJson);

        return $mtime === false || $size === false ? 'none' : substr(hash('xxh3', $mtime . ':' . $size), 0, 8);
    }

    private function createBladeEngine(
        array $templateDirs,
        \Preflow\View\AssetCollector $assets,
        DebugLevel $debug,
        ?\Preflow\View\Geo\GeoCollector $geo = null,
        ?\Preflow\View\Seo\SeoContext $seo = null,
    ): \Preflow\Blade\BladeEngine {
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
            geoCollector: $geo,
            seoContext: $seo,
            liveParts: $this->container->get(\Preflow\Core\PageCache\LiveParts::class),
            pageCache: $this->container->get(\Preflow\Core\PageCache\PageCache::class),
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
        $validationProvider = new \Preflow\Validation\ValidationExtensionProvider($this->container->get(\Preflow\Core\Http\OldInput::class));
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
            $this->container->get(\Preflow\Core\Http\OldInput::class),
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

        // A component that failed renders its boundary and the page stays 200: never store that page.
        $pageCache = $this->container->get(\Preflow\Core\PageCache\PageCache::class);
        $errorBoundary = new \Preflow\Components\ErrorBoundary(
            debug: $debug,
            onError: static function () use ($pageCache): void {
                $pageCache->refuse('component-error');
            },
        );
        $assets = $this->container->has(\Preflow\View\AssetCollector::class)
            ? $this->container->get(\Preflow\View\AssetCollector::class)
            : null;
        $geo = $this->container->has(\Preflow\View\Geo\GeoCollector::class)
            ? $this->container->get(\Preflow\View\Geo\GeoCollector::class)
            : null;
        $renderer = new \Preflow\Components\ComponentRenderer($engine, $errorBoundary, $collector, $assets, $geo);
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

        // Components marked #[Live] are live parts of cached pages
        $renderer->enableLive($this->container->get(\Preflow\Core\PageCache\LiveParts::class), $componentFactory);
        // Components marked #[Freshness] resolve and render with that freshness for what they read
        $renderer->enableFreshness($pageCache);

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
            $htmxConfig = (array) ($this->configFile('htmx') ?? []);
            $htmxAssets = new \Preflow\Htmx\HtmxAssets($assets, (string) ($htmxConfig['include'] ?? 'always'));
            $this->container->instance(\Preflow\Htmx\HtmxAssets::class, $htmxAssets);
            $htmxDriver->useAssets($htmxAssets); // form helpers (form_begin, inline validation) need htmx too
            if ($htmxAssets->mode() === 'always') {
                $htmxAssets->require();
            }

            // Register hd template functions
            // On a /de/… page component URLs are /de/--component/…, so actions run in that
            // locale. Lazy: the locale middleware is registered later (bootI18n).
            $container = $this->container;
            $htmxProvider = new \Preflow\Htmx\HtmxExtensionProvider(
                $htmxDriver,
                $componentToken,
                assets: $htmxAssets,
                pathPrefix: fn (): string => $container->bound(\Preflow\I18n\LocaleMiddleware::class)
                    ? $container->get(\Preflow\I18n\LocaleMiddleware::class)->urlPrefix()
                    : '',
            );
            $this->registerExtensionProvider($engine, $htmxProvider);

            // Component endpoint
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

        // Route sets are compiled once into the boot manifest (Router::compiled()): with a current
        // manifest no pattern is compiled per request. The admin's are not in it (Folio adds them
        // live on the first admin request).
        $manifest = $this->manifest;
        $router = new \Preflow\Routing\Router(
            pagesDir: is_dir($pagesDir) ? $pagesDir : null,
            controllers: $controllers,
            templateExtension: $this->container->has(\Preflow\View\TemplateEngineInterface::class)
                ? $this->container->get(\Preflow\View\TemplateEngineInterface::class)->getTemplateExtension()
                : 'twig',
            store: $manifest->active() || $manifest->isRecording()
                ? static fn (string $set, \Closure $compile, array $sources): array => $manifest->routes($set, $compile, $sources)
                : null,
        );

        $this->setRouter($router);
        // The app's own routes are built on first use (often by a provider's boot): built while the manifest is, too.
        // app/pages is a source even when absent, so `optimize --check` reports one created since.
        $manifest->addSources([$pagesDir]);
        $manifest->whenBuilding(static fn (): \Preflow\Routing\RouteCollection => $router->getCollection());
    }

    /**
     * Content locales for translatable fields, from config/i18n.php (the same
     * file the i18n package reads); a single app.locale without it.
     */
    private function contentLocales(): \Preflow\Data\Locales
    {
        $config = (array) ($this->configFile('i18n') ?? []);
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
        $i18nFile = $this->configFile('i18n');
        $localized = is_dir($langDir) || $i18nFile !== null;

        $i18nConfig = (array) ($i18nFile ?? []);
        $locale = (string) ($i18nConfig['default'] ?? $this->config->get('app.locale', 'en'));
        $fallback = (string) ($i18nConfig['fallback'] ?? $locale);
        $available = array_values((array) ($i18nConfig['available'] ?? [$locale]));
        $strategy = (string) ($i18nConfig['url_strategy'] ?? 'prefix');

        $translator = new \Preflow\I18n\Translator($langDir, $locale, $fallback);
        $this->container->instance(\Preflow\I18n\Translator::class, $translator);
        if (class_exists(\Preflow\I18n\TranslationRegistry::class)) {
            $registry = $this->translationRegistry();
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
            $localeMiddleware = new \Preflow\I18n\LocaleMiddleware(
                $translator,
                $available,
                $locale,
                $strategy,
                (bool) ($i18nConfig['cookie'] ?? $strategy !== 'prefix'),
            );
            $this->container->instance(\Preflow\I18n\LocaleMiddleware::class, $localeMiddleware);
            $this->addMiddleware($localeMiddleware);
        }
    }

    /**
     * The translation groups installed packages declare (extra.preflow.translations), from the
     * boot manifest when it has them.
     */
    private function translationRegistry(): \Preflow\I18n\TranslationRegistry
    {
        $class = \Preflow\I18n\TranslationRegistry::class;
        $installed = $this->basePath('vendor/composer/installed.json');
        if (!method_exists($class, 'fromArray')) {
            return $class::fromInstalled($installed, $this->composerCachePath()); // preflow/i18n before 0.59
        }

        return $class::fromArray($this->manifest->remember(
            'i18n.translations',
            fn (): array => $class::fromPackages($installed, $this->installedPackages())->toArray(),
            [$installed],
        ));
    }

    /**
     * The installed packages (vendor/composer/installed.json, as InstalledPackages reads it),
     * from the boot manifest when it has them.
     *
     * @return list<array<string, mixed>>
     */
    public function installedPackages(): array
    {
        return $this->manifest->packages(fn (): array => \Preflow\Core\Composer\InstalledPackages::read(
            $this->basePath('vendor/composer/installed.json'),
            $this->composerCachePath(),
        ));
    }

    private function bootNotify(): void
    {
        if (!class_exists(\Preflow\Notify\Notifier::class)) {
            return;
        }

        $notifyConfig = $this->configFile('notify');
        if ($notifyConfig === null) {
            return;
        }

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
        $providers = $this->configFile('providers');
        if ($providers !== null) {
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
        $middlewareClasses = $this->configFile('middleware');
        if ($middlewareClasses === null) {
            return;
        }
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

    /**
     * Redirects (preflow/routing) when a provider bound a RedirectStore: a public GET/HEAD
     * that ends in a thrown 404 is answered from the store, else noted in the not-found
     * log (when bound and not switched off with 'not_found' => false in config/redirects.php).
     */
    private function bootRedirects(DebugLevel $debug): void
    {
        if (!class_exists(\Preflow\Routing\Redirect\RedirectMiddleware::class)
            || !$this->container->bound(\Preflow\Routing\Redirect\RedirectStore::class)) {
            return;
        }

        $redirects = $this->configFile('redirects');
        $config = $redirects === null
            ? new \Preflow\Routing\Redirect\RedirectConfig()
            : (method_exists(\Preflow\Routing\Redirect\RedirectConfig::class, 'fromArray')
                ? \Preflow\Routing\Redirect\RedirectConfig::fromArray((array) $redirects)
                : \Preflow\Routing\Redirect\RedirectConfig::fromFile($this->basePath('config/redirects.php')));
        [, $defaultLocale] = $this->siteLocales();
        $container = $this->container;

        $this->addMiddleware(new \Preflow\Routing\Redirect\RedirectMiddleware(
            store: $container->get(\Preflow\Routing\Redirect\RedirectStore::class),
            targets: $container->bound(\Preflow\Routing\Redirect\RedirectTargets::class)
                ? $container->get(\Preflow\Routing\Redirect\RedirectTargets::class)
                : null,
            log: $config->recordNotFound && $container->bound(\Preflow\Routing\Redirect\NotFoundLog::class)
                ? $container->get(\Preflow\Routing\Redirect\NotFoundLog::class)
                : null,
            nonPublic: $container->get(\Preflow\Core\Http\NonPublicPaths::class),
            filter: new \Preflow\Routing\Redirect\NotFoundFilter($config->ignore),
            defaultLocale: $defaultLocale,
            now: static fn (): \DateTimeImmutable => new \DateTimeImmutable(),
            warn: $debug->isDebug() ? $this->csrfWarning(...) : null,
            // The visitor's address includes the locale prefix LocaleMiddleware stripped.
            urlPrefix: static fn (): string => class_exists(\Preflow\I18n\LocaleMiddleware::class)
                && $container->bound(\Preflow\I18n\LocaleMiddleware::class)
                    ? $container->get(\Preflow\I18n\LocaleMiddleware::class)->urlPrefix()
                    : '',
        ));
    }

    /**
     * The site's locales and its default: every available locale when they
     * have URL prefixes (i18n url_strategy "prefix"), else only the default.
     *
     * @return array{list<string>, string}
     */
    private function siteLocales(): array
    {
        $i18n = (array) ($this->configFile('i18n') ?? []);
        $defaultLocale = (string) ($i18n['default'] ?? $this->config->get('app.locale', 'en'));
        $locales = ($i18n['url_strategy'] ?? 'prefix') === 'prefix'
            ? array_values(array_map('strval', (array) ($i18n['available'] ?? [$defaultLocale])))
            : [$defaultLocale];

        return [$locales, $defaultLocale];
    }

    /**
     * SEO (config/seo.php): robots.txt, sitemap.xml and security.txt, head
     * tags and JSON-LD on HTML pages, ETag and 304 for pages that are the
     * same for everyone. Prepended to the pipeline just before GEO, so GEO
     * stays outermost and its internal renders pass through SEO too; each
     * render gets its own SeoContext.
     */
    private function bootSeo(): void
    {
        if (!$this->container->bound(\Preflow\View\Seo\SeoConfig::class)) {
            return;
        }

        $config = $this->container->get(\Preflow\View\Seo\SeoConfig::class);
        if (!$config->enabled()) {
            return;
        }

        $context = $this->container->get(\Preflow\View\Seo\SeoContext::class);
        $this->container->get(\Preflow\View\Geo\RenderScopes::class)->add(
            static fn (ServerRequestInterface $sub, \Closure $next): ResponseInterface => $context->scoped($next),
        );

        [$locales, $defaultLocale] = $this->siteLocales();
        $appUrl = $this->config->get('app.url');
        $geo = $this->container->get(\Preflow\View\Geo\GeoConfig::class);

        $middleware = new \Preflow\View\Seo\SeoMiddleware(
            $config,
            $context,
            $this->container->get(\Preflow\View\Seo\PublicPages::class),
            $this->container->get(\Preflow\View\Seo\SchemaRegistry::class),
            $this->container->get(\Preflow\View\Seo\SeoSite::class),
            $this->container->get(\Preflow\View\NonceGenerator::class),
            is_string($appUrl) ? $appUrl : null,
            $locales,
            $defaultLocale,
            (string) $this->config->get('app.name', ''),
            $geo->enabled(),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable(),
            $this->sessionCookieName(),
        );
        $this->container->instance(\Preflow\View\Seo\SeoMiddleware::class, $middleware);
        $this->pipeline->prepend($middleware);

        // SEO adds its head tags outside the page cache, from the meta the page set while it
        // rendered and the site's defaults (on a Folio site, read from the database): a cached
        // page carries both, read during its render (so a change of the site settings refreshes
        // it), and hands them back on every hit, which then reads nothing.
        $site = $this->container->get(\Preflow\View\Seo\SeoSite::class);
        $pageCache = $this->container->get(\Preflow\Core\PageCache\PageCache::class);
        $pageCache->carry(
            'seo',
            static function () use ($context, $middleware, $site, $pageCache): array {
                $meta = $context->get();
                $state = ['meta' => $meta === null ? null : get_object_vars($meta)];
                $key = $pageCache->key();
                if ($key !== null) {
                    $locale = $middleware->pageLocale((string) parse_url($key->url(), PHP_URL_PATH));
                    $defaults = $site->defaultsFor($locale);
                    $state['locale'] = $locale;
                    $state['defaults'] = $defaults === null ? null : get_object_vars($defaults);
                }

                return $state;
            },
            static function (mixed $value) use ($context): void {
                if (!is_array($value)) {
                    return;
                }
                try {
                    if (is_array($value['meta'] ?? null)) {
                        $context->set(new \Preflow\View\Seo\PageMeta(...$value['meta']));
                    }
                    if (is_string($value['locale'] ?? null)) {
                        $defaults = $value['defaults'] ?? null;
                        $context->useDefaults($value['locale'], is_array($defaults) ? new \Preflow\View\Seo\PageMeta(...$defaults) : null);
                    }
                } catch (\Throwable) {
                    // A page stored by another version of PageMeta: the tags fall back to the live defaults.
                }
            },
        );
    }

    /**
     * The session cookie's name from config/auth.php (`session.cookie`), as
     * NativeSession sets it; null leaves it to session_name().
     */
    private function sessionCookieName(): ?string
    {
        if (!$this->hasConfigFile('auth')) {
            return null;
        }
        $cookie = $this->authConfig()['session']['cookie'] ?? null;

        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }

    /**
     * GEO (config/geo.php): Markdown views of public pages, llms.txt and
     * llms-full.txt (from GeoEntries, per locale). Prepended to the
     * pipeline, so it sees each request before JSON body, security headers,
     * locale, session, CSRF and auth middleware, and its internal renders pass
     * through all of them as fresh anonymous requests.
     */
    private function bootGeo(DebugLevel $debug): void
    {
        if (!$this->container->bound(\Preflow\View\Geo\GeoCollector::class)
            || !$this->container->bound(\Preflow\View\AssetCollector::class)
            || !$this->container->bound(\Preflow\View\Geo\RenderScopes::class)
            || !$this->container->bound(\Preflow\View\Geo\GeoConfig::class)) {
            return;
        }

        $config = $this->container->get(\Preflow\View\Geo\GeoConfig::class);
        $geo = $this->container->get(\Preflow\View\Geo\GeoCollector::class);
        $converter = new \Preflow\View\Geo\MarkdownConverter($geo);
        $this->container->instance(\Preflow\View\Geo\MarkdownConverter::class, $converter);

        if (!$config->enabled()) {
            return;
        }

        $scopes = $this->internalRenderScopes();

        // Render failures that aren't HTTP answers: rethrown in debug (the error page shows them), logged otherwise.
        $container = $this->container;
        $report = static function (\Throwable $e) use ($container): void {
            $message = 'GEO: a page could not be rendered as Markdown: ' . $e::class . ': ' . $e->getMessage();
            if ($container->bound(\Psr\Log\LoggerInterface::class)) {
                $container->get(\Psr\Log\LoggerInterface::class)->error($message, ['exception' => $e]);

                return;
            }
            error_log($message . ' in ' . $e->getFile() . ':' . $e->getLine());
        };

        // Every locale has its llms.txt: the default at /llms.txt, the others under their URL prefix.
        [$locales, $defaultLocale] = $this->siteLocales();

        $appUrl = $this->config->get('app.url');
        $middleware = new \Preflow\View\Geo\GeoMiddleware(
            $config,
            $geo,
            $converter,
            $this->container->get(\Preflow\View\AssetCollector::class),
            is_string($appUrl) ? $appUrl : null,
            $scopes,
            $report,
            $debug->isDebug(),
            $this->container->get(\Preflow\View\Geo\GeoEntries::class),
            (string) $this->config->get('app.name', ''),
            $locales,
            $defaultLocale,
            // preflow/view's geo texts, registered here too for apps whose Composer metadata doesn't list them.
            static function () use ($container): ?object {
                if (!class_exists(\Preflow\I18n\Translator::class) || !$container->bound(\Preflow\I18n\Translator::class)) {
                    return null;
                }
                $translator = $container->get(\Preflow\I18n\Translator::class);
                $translator->addDefaultsIfMissing('geo', \Preflow\View\Geo\LlmsTxt::LANG_PATH, 'public');

                return $translator;
            },
        );
        $this->container->instance(\Preflow\View\Geo\GeoMiddleware::class, $middleware);
        $this->pipeline->prepend($middleware);
    }

    /**
     * The SEO audit's PageRenderer: renders a page through the app (handle(), so the
     * whole pipeline) inside the internal render scopes, from the console too. Built
     * on first use only, so web requests don't pay for it. The debug collector (debug
     * mode) starts empty for each render.
     */
    private function bootAudit(): void
    {
        if (!$this->container->bound(\Preflow\View\Audit\AuditRegistry::class)
            || !$this->container->bound(\Preflow\View\Geo\RenderScopes::class)) {
            return;
        }

        $this->container->singleton(\Preflow\View\Audit\PageRenderer::class, function (): \Preflow\View\Audit\PageRenderer {
            [$locales, $defaultLocale] = $this->siteLocales();
            $appUrl = $this->config->get('app.url');

            $collector = $this->container->bound(DebugCollector::class) ? $this->container->get(DebugCollector::class) : null;

            return new \Preflow\View\Audit\PageRenderer(
                function (ServerRequestInterface $request) use ($collector): ResponseInterface {
                    // In debug mode each request logs into the one collector; renders don't pile up.
                    $collector?->reset();

                    return $this->handle($request);
                },
                is_string($appUrl) ? $appUrl : '',
                $this->internalRenderScopes(),
                $this->container->bound(\Preflow\View\AssetCollector::class) ? $this->container->get(\Preflow\View\AssetCollector::class) : null,
                $locales,
                $defaultLocale,
            );
        });
    }

    /**
     * The scopes around an internal render (GEO's, the SEO audit's): the core scope
     * outermost, around the ones packages added during their registration.
     */
    private function internalRenderScopes(): \Preflow\View\Geo\RenderScopes
    {
        $scopes = new \Preflow\View\Geo\RenderScopes();
        $scopes->add($this->anonymousRenderScope());
        $packageScopes = $this->container->get(\Preflow\View\Geo\RenderScopes::class);
        $scopes->add(static fn (ServerRequestInterface $sub, \Closure $next): ResponseInterface => $packageScopes->run($sub, $next));

        return $scopes;
    }

    /**
     * Wraps an internal render (GEO, the SEO audit): the request context names the rendered page,
     * the visitor's session data is hidden (templates read the session directly,
     * e.g. flash(); a shutdown hook restores it even after a fatal error), nobody
     * is signed in during it, and the translator locale and signed-in users are
     * put back afterwards, so nothing passes between the request and its renders.
     *
     * @return \Closure(ServerRequestInterface, \Closure(): ResponseInterface): ResponseInterface
     */
    private function anonymousRenderScope(): \Closure
    {
        $container = $this->container;
        $sessionData = new \Preflow\Core\Http\Session\SessionDataGuard();

        return static function (ServerRequestInterface $sub, \Closure $next) use ($container, $sessionData): ResponseInterface {
            $context = $container->bound(RequestContext::class) ? $container->get(RequestContext::class) : null;
            $container->instance(RequestContext::class, new RequestContext($sub->getUri()->getPath(), 'GET'));

            $translator = $container->bound(\Preflow\I18n\Translator::class)
                ? $container->get(\Preflow\I18n\Translator::class)
                : null;
            $locale = $translator?->getLocale();

            $auth = $container->bound(\Preflow\Auth\AuthManager::class)
                ? $container->get(\Preflow\Auth\AuthManager::class)
                : null;
            $users = $auth?->users() ?? [];
            $guard = $auth?->guardName();

            $sessionData->hide();
            // Nobody is signed in for the render, even when it is called from inside the pipeline.
            $auth?->setUser(null);

            try {
                return $next();
            } finally {
                $sessionData->restore();
                if ($auth !== null) {
                    $auth->setUser($guard !== null ? ($users[$guard] ?? null) : null, $guard);
                    foreach ($users as $name => $user) {
                        if ($name !== $guard) {
                            $auth->addUser($user, $name);
                        }
                    }
                }
                if ($translator !== null && $locale !== null) {
                    $translator->setLocale($locale);
                }
                if ($context !== null) {
                    $container->instance(RequestContext::class, $context);
                }
            }
        };
    }

    private function bootSession(): void
    {
        if (!class_exists(\Preflow\Core\Http\Session\NativeSession::class)) {
            return;
        }

        if (!$this->hasConfigFile('auth')) {
            return;
        }

        $authConfig = $this->authConfig();
        $sessionConfig = $authConfig['session'] ?? [];

        if ($sessionConfig === []) {
            return;
        }

        // session.paths limits new sessions (and their cookie) to these path prefixes; a visitor
        // who brings the session cookie resumes it on every path. Without it the session starts
        // on every request.
        $paths = isset($sessionConfig['paths']) ? array_values((array) $sessionConfig['paths']) : null;
        unset($sessionConfig['paths']);

        $session = new \Preflow\Core\Http\Session\NativeSession($sessionConfig);
        if ($paths === null) {
            $session->start();
        }
        $this->container->instance(\Preflow\Core\Http\Session\SessionInterface::class, $session);

        $this->addMiddleware(new \Preflow\Core\Http\Session\SessionMiddleware($session, $paths, $this->sessionCookieName()));
    }

    /** @return array<string, mixed> config/auth.php, loaded once (it may hash passwords) */
    private function authConfig(): array
    {
        return (array) $this->configFile('auth');
    }

    /**
     * CSRF on every request, with or without a session (config/csrf.php). The sign-in path
     * registry and the stateless token are bound before the providers run, so packages can
     * add their login forms and print the token.
     */
    private function bootCsrf(DebugLevel $debug, \Preflow\Core\Http\Csrf\StatelessCsrfToken $stateless): void
    {
        $csrf = $this->configFile('csrf');
        $config = $csrf === null ? new \Preflow\Core\Http\Csrf\CsrfConfig() : \Preflow\Core\Http\Csrf\CsrfConfig::fromArray((array) $csrf);

        $signIn = new \Preflow\Core\Http\Csrf\CsrfSignInPaths();
        foreach ($config->signInPaths as $path) {
            $signIn->add($path);
        }
        $this->container->instance(\Preflow\Core\Http\Csrf\CsrfSignInPaths::class, $signIn);
        $this->container->instance(\Preflow\Core\Http\Csrf\StatelessCsrfToken::class, $stateless);

        $appUrl = $this->config->get('app.url');
        $appUrl = is_string($appUrl) && $appUrl !== '' ? $appUrl : null;

        // Without a site handler: the form goes back with its fields kept, never applied.
        $container = $this->container;
        $responder = new \Preflow\Core\Http\Csrf\ExpiredSessionResponder(
            (string) $this->config->get('app.locale', 'en'),
            static fn (): ?object => class_exists(\Preflow\I18n\Translator::class) && $container->bound(\Preflow\I18n\Translator::class)
                ? $container->get(\Preflow\I18n\Translator::class)
                : null,
            $appUrl,
        );

        // A site's ExpiredSessionHandler is bound by its providers, which run after this:
        // the middleware looks it up when a POST's session turns out to have expired.
        $this->addMiddleware(new \Preflow\Core\Http\Csrf\CsrfMiddleware(
            config: $config,
            origin: new \Preflow\Core\Http\Csrf\OriginCheck($appUrl, $config->trustedOrigins),
            stateless: $stateless,
            signIn: $signIn,
            sessionCookie: $this->sessionCookieName(),
            router: fn (): ?RouterInterface => $this->router,
            expiredSessionHandler: new \Preflow\Core\Http\Csrf\ContainerExpiredSessionHandler($this->container),
            expiredResponder: $responder(...),
            warn: $debug->isDebug() ? $this->csrfWarning(...) : null,
        ));
    }

    /** Debug-mode CSRF and redirect warnings: to the bound logger (a provider may bind it after boot), else the PHP log. */
    private function csrfWarning(string $message): void
    {
        if ($this->container->has(\Psr\Log\LoggerInterface::class)) {
            $this->container->get(\Psr\Log\LoggerInterface::class)->warning($message);
            return;
        }
        error_log($message);
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

        $registry = new \Preflow\Auth\Access\PermissionRegistry();
        $this->container->instance(\Preflow\Auth\Access\PermissionRegistry::class, $registry);
        if (!$this->hasConfigFile('auth')) {
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
            // remember-me cookie; without it there is none. Its Secure flag is the entry's own 'secure', else
            // the session's (auth.session.secure, as the session cookie), else APP_SECURE.
            $remember = (array) ($guardConfig['remember'] ?? []);
            if ($remember !== [] && $guardClass === \Preflow\Auth\SessionGuard::class) {
                if (!$this->container->has(\Preflow\Auth\Remember\RememberMe::class)) {
                    throw new \RuntimeException("Guard \"{$name}\" remembers users: that needs preflow/data configured (config/data.php).");
                }
                $remember += ['cookie' => 'remember_' . $name, 'days' => 30, 'path' => '/'];
                $remember['secure'] = (bool) ($remember['secure'] ?? $authConfig['session']['secure'] ?? (getenv('APP_SECURE') ?: false));
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
                    array_filter([
                        'lifetime' => (int) ($authConfig['session']['lifetime'] ?? 0),
                        'domain' => (string) ($authConfig['session']['domain'] ?? ''),
                        'secure' => (bool) ($authConfig['session']['secure'] ?? false),
                    ]),
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

        $loginPaths = [];
        foreach ($authConfig['guards'] ?? [] as $name => $guardConfig) {
            if (is_string($guardConfig['login'] ?? null)) {
                $loginPaths[(string) $name] = $guardConfig['login'];
            }
        }
        $authManager = new \Preflow\Auth\AuthManager($guards, $defaultGuard, $loginPaths);
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
            new \Preflow\Auth\Http\AuthMiddleware(
                $authManager,
                null,
                $this->container->has(\Preflow\Core\Http\Api\ApiConfig::class) ? $this->container->get(\Preflow\Core\Http\Api\ApiConfig::class) : null,
            ),
        );
        $this->container->instance(
            \Preflow\Auth\Http\TokenAuthMiddleware::class,
            new \Preflow\Auth\Http\TokenAuthMiddleware($authManager, isset($authConfig['api_guard']) ? (string) $authConfig['api_guard'] : null),
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
            $authProvider = new \Preflow\Auth\AuthExtensionProvider(
                $authManager,
                $session,
                $access,
                $this->container->get(\Preflow\Core\Http\Csrf\StatelessCsrfToken::class),
            );
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

        return $this->manifest->remember('core.components', function () use ($dir): array {
            if (!is_dir($dir)) {
                return [];
            }
            $map = [];
            $this->scanComponentsRecursive($dir, 'App\\Components', $map);

            return $map;
        }, [$dir]);
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

        return $this->manifest->remember('core.controllers', function () use ($dir): array {
            if (!is_dir($dir)) {
                return [];
            }
            $controllers = [];
            $this->scanControllersRecursive($dir, 'App\\Controllers', $controllers);

            return $controllers;
        }, [$dir]);
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

            // Returning data from a controller is the short way to answer JSON.
            if (is_array($response) || $response instanceof \JsonSerializable) {
                return \Preflow\Core\Http\Json::response($response);
            }

            return new \Nyholm\Psr7\Response(200, ['Content-Type' => 'text/html'], (string) $response);
        };
    }

    private function ensureComponentRenderer(): void
    {
        $container = $this->container;

        // Controllers render page templates through this. Not shared: it reads the request
        // being handled, which changes per request.
        if ($container->has(\Preflow\View\TemplateEngineInterface::class) && !$container->bound(\Preflow\View\PageResponder::class)) {
            $container->bind(
                \Preflow\View\PageResponder::class,
                static fn (Container $c): \Preflow\View\PageResponder => new \Preflow\View\PageResponder(
                    $c->get(\Preflow\View\TemplateEngineInterface::class),
                    $c->get(\Preflow\View\AssetCollector::class),
                    $c->bound(RequestContext::class) ? $c->get(RequestContext::class) : null,
                ),
            );
        }

        if ($this->componentRenderer !== null) {
            return;
        }

        $this->componentRenderer = function (Route $route, ServerRequestInterface $request) use ($container): ResponseInterface {
            if ($container->has(\Preflow\View\TemplateEngineInterface::class)) {
                $engine = $container->get(\Preflow\View\TemplateEngineInterface::class);
                $html = $engine->render($route->handler, [
                    'route' => (object) $route->parameters,
                    'request' => \Preflow\View\PageResponder::requestVariable($request),
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
