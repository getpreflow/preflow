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
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RenderFrame;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Context\Site;
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
    /**
     * The name of the onEnter() hook that makes a frame opened with Contexts::part() part of its
     * caller's page (page cache recording and refusals, CSP nonce).
     *
     * @since 0.81.0
     */
    public const string PART_HOOK = 'preflow.part_of_caller';

    /**
     * The request attribute that derives the context of the frame handle() opens for the request
     * (withDerivedContext()). Request attributes are set in-process only, never by a client.
     *
     * @since 0.81.0
     */
    public const string DERIVE_CONTEXT = 'preflow.context.derive';

    /**
     * $request with $derive added to how handle() builds its frame's context: after
     * RenderContext::fromRequest() (and after a derivation the request carries already), before
     * the frame opens, so the whole request (its first middleware too) runs under it. Inside a
     * frame of something else the request's frame is still never looser than that frame (a
     * preview, a role view, an audit). For in-process callers: a test that signs a user in for
     * one request (preflow/testing's actingAs()) or renders it as of a date. render() ignores it:
     * its caller gives the context.
     *
     * @since 0.81.0
     * @param \Closure(RenderContext, Container): RenderContext $derive gets the context and the app's container
     */
    public static function withDerivedContext(ServerRequestInterface $request, \Closure $derive): ServerRequestInterface
    {
        $before = $request->getAttribute(self::DERIVE_CONTEXT);
        if (!$before instanceof \Closure) {
            return $request->withAttribute(self::DERIVE_CONTEXT, $derive);
        }

        return $request->withAttribute(
            self::DERIVE_CONTEXT,
            static fn (RenderContext $ctx, Container $container): RenderContext => $derive($before($ctx, $container), $container),
        );
    }

    /** $ctx derived as $request asks (withDerivedContext()). */
    private function derived(RenderContext $ctx, ServerRequestInterface $request): RenderContext
    {
        $derive = $request->getAttribute(self::DERIVE_CONTEXT);
        if (!$derive instanceof \Closure) {
            return $ctx;
        }
        $derived = $derive($ctx, $this->container);
        if (!$derived instanceof RenderContext) {
            throw new \UnexpectedValueException('A context derivation (Application::withDerivedContext()) returned ' . get_debug_type($derived) . ', not a RenderContext.');
        }

        return $derived;
    }

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

    /** @var list<\Closure(): void>|null see onProvidersBooted(); null once they ran */
    private ?array $providersBootedHooks = [];

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
     * @param bool|null $console Overrides runningInConsole() from the start (see setRunningInConsole()); null follows PHP_SAPI. The web FailureGuard is installed when the app is not the console.
     */
    public static function create(string|array $basePath = '.', ?bool $console = null): self
    {
        if (is_array($basePath)) {
            // Legacy/testing: pass config directly
            return new self(new Config($basePath), getcwd() ?: '.');
        }

        EnvLoader::load(rtrim($basePath, '/') . '/.env');

        $app = new self(new Config(), $basePath);
        if ($console !== null) {
            $app->setRunningInConsole($console);
        }
        // A web request that fails before the kernel (a broken config file, boot, a PHP Error, a
        // fatal) answers 500, not PHP's raw output; the console keeps its own exception output.
        if (!$app->runningInConsole()) {
            \Preflow\Core\Error\FailureGuard::install($app);
        }
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
     * It is used only outside the console, with app.debug off, and while the sources checked on
     * every request are unchanged: the core's (.env, Composer's package list) and those packages
     * declare (composer `extra.preflow.runtime`); otherwise it is off and every value is computed
     * live. Packages take their catalogs from it through BootManifest::remember().
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

        // A router that can ask answers 405 for a wrong method behind a catch-all only where no
        // page answers the path: it asks the page lookups, when a request runs.
        if ($router instanceof \Preflow\Core\Routing\AnswersPages) {
            $container = $this->container;
            $router->answerPagesWith(static function (string $locale, string $path) use ($container): bool {
                foreach (self::pageLookups($container) as $lookup) {
                    if ($lookup->exists($locale, $path)) {
                        return true;
                    }
                }

                return false;
            });
        }
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
     * Calls $hook once during boot(), when every provider's boot() has run and the user providers
     * of config/auth.php are built: what one provider sets up there may rely on what a provider
     * after it defines in its boot() (a package's user type, e.g. rbac's "staff", which a record
     * provider needs). Folio sets up its admin then in the console. Hooks run in the order added;
     * one added after that point runs at once.
     *
     * @param \Closure(): void $hook
     */
    public function onProvidersBooted(\Closure $hook): void
    {
        if ($this->providersBootedHooks === null) {
            $hook();

            return;
        }
        $this->providersBootedHooks[] = $hook;
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
        // The hypermedia clients (the app's driver, packages' own): filled while the boot runs, read per request
        $protocols = new \Preflow\Core\Http\Hypermedia\HypermediaProtocols();
        $this->container->instance(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class, $protocols);
        // An htmx app's requests are htmx requests even where no driver gets wired (no components,
        // no template engine): the header-only htmx client from the start. The configured
        // HtmxDriver later counts as the same client (one per class).
        $hypermedia = $this->config->get('app.hypermedia') ?: \Preflow\Htmx\HtmxDriver::class;
        if ($hypermedia === \Preflow\Htmx\HtmxDriver::class && class_exists(\Preflow\Htmx\HtmxDriver::class)) {
            $protocols->add(new \Preflow\Htmx\HtmxDriver(new \Preflow\Htmx\ResponseHeaders()));
        }
        $this->bootPageCache();

        $debug = DebugLevel::from((int) $this->config->get('app.debug', 0));
        $secretKey = $this->config->get('app.key', \Preflow\Core\Http\Csrf\StatelessCsrfToken::DEFAULT_KEY);
        // No weak key in production (owner decision, 0.81): a web app refuses to start with one;
        // debug mode warns once (reportWeakKey()); the console boots, so key:generate can fix it.
        $keyProblem = \Preflow\Core\Security\AppKey::problem((string) $secretKey);
        if ($keyProblem !== null && !$debug->isDebug() && !$this->runningInConsole()) {
            throw \Preflow\Core\Security\WeakAppKey::because($keyProblem);
        }

        // The render context's edges: debug mode reports a torn frame stack loudly; the system
        // clock (contexts take "now" from it; code reads Contexts::current()->clock); the request,
        // the outbox and the Set-Cookie queue of the render frame current when they are asked for.
        Contexts::debug($debug->isDebug());
        // Internal renders (audit, GEO, rebuilds, verify, previews) see an anonymous visitor's
        // superglobals, for site code that still reads them; once per process, by name.
        Contexts::onEnter(\Preflow\Core\PageCache\AnonymousGlobals::adapter(), \Preflow\Core\PageCache\AnonymousGlobals::HOOK);
        // A frame whose output goes into its caller's (Contexts::part(): a PDF preview in a page,
        // a request the page asks for in-process) is part of that page; once per process, by name.
        Contexts::onEnter($this->joinCallersPage(...), self::PART_HOOK);
        if (!$this->container->bound(\Psr\Clock\ClockInterface::class)) {
            $this->container->instance(\Psr\Clock\ClockInterface::class, new \Preflow\Core\Context\SystemClock());
        }
        $this->container->bind(RequestContext::class, static fn (): ?RequestContext => Contexts::peek()?->request);
        $this->container->bind(\Preflow\Core\Context\Outbox::class, static fn (): \Preflow\Core\Context\Outbox => Contexts::frame()->outbox());
        $this->container->instance(\Preflow\Core\Http\CookieQueue::class, \Preflow\Core\Http\CookieQueue::ofCurrentFrame());
        // Headers a page or component adds (Cache-Control: no-store…), per render frame.
        $hintsContainer = $this->container;
        $this->container->instance(\Preflow\Core\Http\ResponseHeaderHints::class, \Preflow\Core\Http\ResponseHeaderHints::ofCurrentFrame(
            // The headers the app's hypermedia clients own and their nonce headers (htmx: HX-*) are theirs to send.
            static function () use ($hintsContainer): array {
                if (!$hintsContainer->bound(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class)) {
                    return [];
                }
                $clients = $hintsContainer->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class);

                return [...$clients->ownedHeaders(), ...$clients->nonceHeaders()];
            },
        ));

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

        // API prefixes (config/api.php): errors there are JSON, and SEO and GEO leave them alone
        $api = \Preflow\Core\Http\Api\ApiConfig::fromArray($this->configFile('api'));

        // JSON body parsing — must run before session, CSRF, and auth middleware
        $this->pipeline->pipe(new \Preflow\Core\Http\JsonBodyMiddleware($api->maxJsonBody, $api));

        // Fields kept from a form sent back after an expired session; read from the current render's
        // session when asked (an internal render has none, so it never shows a visitor's)
        $this->container->instance(\Preflow\Core\Http\OldInput::class, new \Preflow\Core\Http\OldInput(
            static fn (): ?\Preflow\Core\Http\Session\SessionInterface => \Preflow\Core\Http\Session\SessionMiddleware::ofCurrentRender(),
        ));
        $container = $this->container;

        // Admin, API and endpoint prefixes that are not public pages; packages add theirs in their providers
        $nonPublic = new \Preflow\Core\Http\NonPublicPaths();
        $this->container->instance(\Preflow\Core\Http\NonPublicPaths::class, $nonPublic);

        // Where page lookups are bound (core's PageExists first); packages add their own names for it
        $this->container->instance(\Preflow\Core\Routing\PageLookups::class, new \Preflow\Core\Routing\PageLookups());

        $this->container->instance(\Preflow\Core\Http\Api\ApiConfig::class, $api);
        foreach ($api->prefixes as $prefix) {
            $nonPublic->add($prefix);
        }

        // The activity log keeps nothing until the data layer binds its stored one (a provider may bind its own).
        $this->container->singleton(\Preflow\Core\Activity\ActivityLog::class, static fn (): \Preflow\Core\Activity\ActivityLog => new \Preflow\Core\Activity\NullActivityLog());
        // The labels of the markers written as an event's person where no person acted ("member-deletion").
        $this->container->instance(\Preflow\Core\Activity\ActivityActors::class, new \Preflow\Core\Activity\ActivityActors());

        // Auto-discover installed packages and wire them up
        $this->bootValidators();
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
        // The site, from config and the locale URL rule bootI18n() bound.
        $this->container->instance(Site::class, $this->site());
        $this->bootNotify();
        $this->bootPageCacheServing($debug);
        $this->bootSession();
        $this->bootCsrf($debug, new \Preflow\Core\Http\Csrf\StatelessCsrfToken((string) $secretKey));
        // Signed preview tokens (a key derived from app.key) and the gate each use is checked
        // against: nobody without auth; bootAuth() binds auth's, an app's provider may bind its own.
        $this->container->instance(\Preflow\Core\Context\Preview\PreviewToken::class, new \Preflow\Core\Context\Preview\PreviewToken((string) $secretKey));
        $this->container->instance(\Preflow\Core\Context\Preview\PreviewGate::class, new \Preflow\Core\Context\Preview\DenyAllPreviewGate());
        $this->bootAuth();

        // Register user service providers
        $this->bootProviders();
        $this->bootLazyUserProviders();
        // After the providers: one of them may bind the app's clock (in register() or boot()).
        $this->consoleOnTheAppsClock();
        $this->runProvidersBootedHooks();
        // After the providers: one of them may bind the site's logger.
        if ($debug->isDebug() && !\Preflow\Core\Security\AppKey::canSign((string) $secretKey)) {
            $this->reportWeakKey();
        }
        $this->checkNotifierQueue($debug);

        // The configured hypermedia driver, built now the providers ran: a failure stops the boot
        // with its reason ("app.hypermedia: could not build …"), not a later request.
        if (class_exists(\Preflow\Htmx\LazyHypermediaDriver::class) && $this->container->bound(\Preflow\Htmx\HypermediaDriver::class)) {
            $driver = $this->container->get(\Preflow\Htmx\HypermediaDriver::class);
            if ($driver instanceof \Preflow\Htmx\LazyHypermediaDriver) {
                $driver->driver();
            }
        }

        // Load user-defined middleware from config/middleware.php
        $this->bootMiddleware();

        // Redirects and the not-found list — after the site's middleware, so a site route wins
        $this->bootRedirects($debug);

        // The site's page lookups are all set up now (providers ran): redirect_bare must have one.
        $this->checkPageLookup();

        // SEO files, head tags and ETag — prepended, then GEO is prepended around it
        $this->bootSeo();

        // GEO Markdown views — prepended, so it runs before every other middleware
        $this->bootGeo($debug);

        // Clean paths (config/routing.php canonical_paths) — prepended around GEO, so '//de//about/'
        // is answered with its clean address before anything reads the path or its locale prefix.
        $this->bootCanonicalPaths();

        // CORS for API paths — prepended last, so it is outermost: it wraps the page cache
        // (stored pages hold no CORS headers, hits get them) and answers preflights first.
        $cors = null;
        if ($api->corsOrigins !== []) {
            $cors = new \Preflow\Core\Http\Api\CorsMiddleware($api);
            $this->pipeline->prepend($cors);
        }

        // The SEO audit's page renderer (through the whole pipeline built above)
        $this->bootAudit();

        // Error handler. A visitor's page is the app's own (app/pages/_errors/) when it has one, else
        // the built-in page; development keeps the detailed page.
        $visitorPage = $this->appErrorPages(new ProdErrorRenderer($this->nonceProvider()));
        $renderer = $debug->isDebug()
            ? new DevErrorRenderer($collector, $this->nonceProvider())
            : $visitorPage;
        // Internal renders (the SEO audit) get the visitor's error page, even in debug mode.
        $errorHandler = new ErrorHandler(
            $renderer,
            $debug->isDebug() ? $visitorPage : null,
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

        // What boot registered (htmx with include 'always', what providers added): every internal
        // render starts from it, nothing more and nothing less.
        if ($this->container->bound(\Preflow\View\AssetCollector::class)) {
            $this->container->get(\Preflow\View\AssetCollector::class)->markBooted();
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
            cookies: $this->container->get(\Preflow\Core\Http\CookieQueue::class),
            cors: $cors,
            hints: $this->container->get(\Preflow\Core\Http\ResponseHeaderHints::class),
            // No setsQueuedCookies: the frame-bound queue brings core's rule (since 0.82.0): a
            // request's frame that delivers sets them, a render() frame never does.
        );
    }

    /**
     * Handles $request through the whole application, as a request of its own. Without a frame
     * around it (a test, a script), the request is the outermost render: handle() opens its frame
     * (RenderContext::fromRequest()), sends what it queued (mail queued before a failure too) and
     * drops its after-response work, unless runAfterResponseInHandle() is on. Inside a frame that
     * belongs to something else (a console command, a site's or a test's own frame, a request that
     * asks for another), it opens a nested frame from the request (the clock of the frame around
     * it) and hands what it queued, notify's mail included, to the frame around it, which sends
     * it when it ends (a console command also when it fails); mail queued before the nested
     * request failed goes with it too. run() and render() render in the frame they opened
     * themselves; an internal render goes through render() with RenderContext::internal().
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->kernel === null) {
            throw new \RuntimeException('Application not booted. Call boot() first.');
        }
        if (Contexts::depth() > 0) {
            return $this->handleNested($request);
        }

        $clients = $this->withHypermedia($request);
        $ctx = $this->requestContext($request, $clients);
        $this->rebuildsScheduled = false;
        $this->signedStateStore = null; // a new request issues its component state anew

        $held = [];
        try {
            return Contexts::run($ctx, function () use ($request, $clients, &$held): ResponseInterface {
                try {
                    $response = $this->handleInFrame($request, $clients);
                } catch (\Throwable $e) {
                    if (self::sends()) {
                        if (!$this->emitting) {
                            $this->logFailure($this->flushDeferredNotifications(...), 'Deferred notifications could not be flushed');
                        } else {
                            $held = self::takeQueuedMail(Contexts::frame());
                        }
                        $this->logFailure(Contexts::deliverQueued(...), 'Queued items could not be sent');
                    }
                    throw $e;
                }
                if (self::sends()) {
                    // In-process callers (CLI, tests) have no later emit step: what the request
                    // queued is sent now. run() sends after the response has gone out instead.
                    // A request whose context captures or discards (a role view a test derived
                    // for it, Application::withDerivedContext()) sends nothing.
                    if (!$this->emitting) {
                        $this->flushDeferredNotifications();
                    } else {
                        $held = self::takeQueuedMail(Contexts::frame());
                    }
                    $this->logFailure(
                        $this->afterResponseInHandle ? Contexts::deliverNow(...) : Contexts::deliverQueued(...),
                        'Queued items could not be sent',
                    );
                }

                return $response;
            });
        } finally {
            $this->holdForFlush($held);
        }
    }

    /** Whether the current frame delivers what it queued (OutboxPolicy::Deliver, as far as its parents let it). */
    private static function sends(): bool
    {
        return Contexts::frame()->outboxPolicy() === \Preflow\Core\Context\OutboxPolicy::Deliver;
    }

    /**
     * While $emitting is set around a request handle() opens itself (a test acting as run():
     * holdMailForFlush()), its mail waits for the next Notifier::flushDeferred(), as run() sends
     * it after the response: it goes to notify's own queue, outside every frame.
     *
     * @param list<object> $mail notify's Deferred items
     */
    private function holdForFlush(array $mail): void
    {
        if ($mail === [] || !$this->container->bound(\Preflow\Notify\Deferred\DeferredQueue::class)) {
            return;
        }
        $queue = $this->container->get(\Preflow\Notify\Deferred\DeferredQueue::class);
        foreach ($mail as $item) {
            if ($item instanceof \Preflow\Notify\Deferred\Deferred) {
                $queue->push($item);
            }
        }
    }

    /**
     * Takes the messages $frame queued through notify (channel 'notify') out of its outbox; the
     * other items stay.
     *
     * @return list<object>
     */
    private static function takeQueuedMail(RenderFrame $frame): array
    {
        // A frame that never made its outbox queued nothing (and makes none now).
        return $frame->madeOutbox()?->take(\Preflow\Core\Context\Bridge\NotifyChannel::NAME) ?? [];
    }

    /**
     * Renders $request under $ctx in a frame of its own and returns what it produced: the
     * response, what its frame captured instead of sending (OutboxPolicy::Capture), and the data
     * writes it skipped, its nested renders' included. The one entry for a render the app makes
     * of itself (the SEO audit, GEO, a page cache rebuild, a preview). The frame around it is
     * untouched: the render cannot see or take its cookies. Set-Cookie values the render left
     * queued (none of its middleware set them) are captured as 'cookies' items under Capture,
     * dropped under Discard, and under Deliver go into the queue of the frame around it (whose
     * response takes them where it takes its own, since 0.81.1); since 0.82.0 also when the render
     * fails (its error page sets none: Contexts::runRender(), RenderFrame::$openedByRender);
     * with no frame around it (depth 0) they are dropped, as 0.80 dropped cookies left queued.
     * What it queues under Deliver reaches the frame around it when its frame closes.
     *
     * @since 0.81.0
     */
    public function render(RenderContext $ctx, ServerRequestInterface $request): RenderResult
    {
        return $this->renderWith($ctx, fn (): ResponseInterface => $this->handleInFrame($request));
    }

    /**
     * The whole-page preview of the page at $path: a GET of $path through the whole application
     * (layout, navigation, SEO, components), rendered with render() in a frame of its own, as an
     * anonymous visitor on the public surface will see it in $locale once the working copies are
     * published (RenderContext::workingCopies()). What it would send is captured and the data
     * writes it would make are skipped, both reported on the result (RenderResult::wouldSend(),
     * wouldWrite()); its purpose is Preview, so it leaves no trace and the page cache neither
     * stores nor serves it. With $as, the visitor is signed in with those roles (view as role,
     * RenderContext::asRole()).
     *
     * With $staffRequest (the request of the staff member who asks for the preview, since
     * 0.81.0), the preview hands a signed preview token on to the requests the page makes later
     * (component actions, lazy parts, its own htmx calls: PreviewUrls), so they render as the
     * preview too: PreviewToken::grant() issues it to that request's session and signed-in user
     * for $requires, the permissions they were checked for (with $as, PreviewGate::VIEW_AS among
     * them), re-checked at every use. Without a real app.key (PreviewToken::enabled()) no token is
     * issued and those requests render as the live site.
     *
     * The context derives from $from (the caller's own, Contexts::current() in an editor's
     * request), so the site, the clock (a frozen one too) and the facets carry over (never a
     * PreviewGrant: a preview token of the caller's; only the one $staffRequest grants); the viewer,
     * the surface, the locale, the read mode, both policies, the purpose and the request do not.
     * The request is a new GET of $path on the site's origin: nothing of the caller's (cookies,
     * session, headers, attributes, the locale prefix its URL carried) reaches the page. The
     * caller's frame is untouched.
     *
     * The result's response is the page's own: its Content-Security-Policy holds the nonce its
     * inline scripts and styles carry. Answer with that response (adding headers, or external
     * files to its body, is fine); pasting its body into another response breaks those scripts.
     *
     * @since 0.81.0
     *
     * @param list<string> $requires with $staffRequest: the permissions the staff member was checked for
     *
     * @throws \InvalidArgumentException when $path is not a path starting with '/', or carries a query or a fragment;
     *         with $staffRequest, see PreviewToken::issue() (no permission named; a role view without PreviewGate::VIEW_AS)
     * @throws \LogicException with $staffRequest, when it has no started session or nobody is signed in on it
     */
    public function preview(RenderContext $from, string $path, string $locale, ?\Preflow\Core\Context\RoleView $as = null, ?ServerRequestInterface $staffRequest = null, array $requires = []): RenderResult
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new \InvalidArgumentException("A preview is a clean GET of a path starting with '/', without a query or fragment, got '{$path}'.");
        }
        // On the site's own origin (app.url), as the SEO audit asks for its pages.
        $request = new \Nyholm\Psr7\ServerRequest('GET', rtrim($from->site->origin, '/') . $path);
        // A preview token $from carries grants the caller's reading mode, not this preview's: it
        // never carries over (a preview's own grant comes from PreviewToken::grant()).
        $ctx = $from->without(\Preflow\Core\Context\Preview\PreviewGrant::class)
            ->asVisitor()->public()->in($locale)->workingCopies()->capturing()->skippingWrites()
            ->because(Purpose::Preview)->for($request)->prefixed('');

        $ctx = $as !== null ? $ctx->asRole($as) : $ctx;
        if ($staffRequest !== null) {
            $tokens = $this->container->get(\Preflow\Core\Context\Preview\PreviewToken::class);
            if ($tokens->enabled()) {
                $ctx = $tokens->grant($ctx, $staffRequest, $requires);
            }
        }

        return $this->render($ctx, $request);
    }

    /**
     * render() with $handle in place of the whole application: the page cache's verify renders
     * the rest of its pipeline for another visitor this way, in a frame of its own exactly as
     * render() opens one.
     *
     * @param \Closure(): ResponseInterface $handle
     */
    private function renderWith(RenderContext $ctx, \Closure $handle): RenderResult
    {
        $frame = null;
        // A frame of a render entry: the Kernel never sets its cookies on the response (the result),
        // also not on its error page; the frame settles what it left by its policy when it closes
        // (CookieQueue::settle(): captured, handed to the frame around it, or dropped).
        $response = Contexts::runRender($ctx, function () use (&$frame, $handle): ResponseInterface {
            $frame = Contexts::frame();

            return $handle();
        });
        $token = $ctx->get(\Preflow\Core\Context\Preview\PreviewGrant::class)?->token();
        if ($token !== null && $response->getStatusCode() < 300) {
            // A render under a preview token hands it to the page's hypermedia clients (ruling R5); an error page never shows it.
            $response = \Preflow\Core\Context\Preview\PreviewUrls::inPage($response, $token, $this->container->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class));
        }
        // A frame that never made its outbox captured nothing (and makes none, nor its parent's, now).
        return new RenderResult($response, $frame->madeOutbox()?->drain() ?? [], $frame->skippedWrites(), $frame->failure());
    }

    /**
     * A request asked for inside a frame that is not its own: a nested frame from the request
     * (nestedContext()). It is part of the page the frame around it renders for the page cache
     * (Contexts::part(), joinCallersPage()), whatever its purpose, and a stretch it
     * renders as another context than the public default refuses that page. What it queued and
     * its after-response work go to the frame around it when it closes, notify's mail included:
     * it goes out when that frame sends (a request after its response; a command when it ends,
     * also when it fails), not at the end of this request as before 0.81. When it fails, the mail
     * it queued before the failure still goes to the frame around it (since 0.81.0; as a failed
     * visitor request still sends its mail): a request of its own is no render that leaves nothing
     * behind. A Notifier with notify's own queue (bound by a test) is flushed here, not under
     * run(), which sends after its response, as handle() did before 0.81.
     */
    private function handleNested(ServerRequestInterface $request): ResponseInterface
    {
        $ctx = $this->nestedContext($request, Contexts::current());
        $outer = Contexts::frame();

        // Part of the page around it (joinCallersPage()): its reads, expiries, refusals and nonce.
        return Contexts::part($ctx, function () use ($request, $outer): ResponseInterface {
            try {
                $response = $this->handleInFrame($request);
            } catch (\Throwable $e) {
                self::handOnQueuedMail(Contexts::frame(), $outer);
                if (!$this->emitting) {
                    $this->logFailure($this->flushDeferredNotifications(...), 'Deferred notifications could not be flushed');
                }
                throw $e;
            }
            if (!$this->emitting) {
                $this->flushDeferredNotifications();
            }
            if (Contexts::frame()->departures() > 0 && $this->container->has(\Preflow\Core\PageCache\PageCache::class)) {
                // It read as another context than the public default: the page around it, which it is
                // part of, is not stored either (a no-op when that page is not rendered for the cache).
                $this->container->get(\Preflow\Core\PageCache\PageCache::class)->refuse('context');
            }

            return $response;
        });
    }

    /**
     * The onEnter() hook for a frame that is part of its caller's output (Contexts::part(): a PDF
     * preview printed into a page, a request the page asks for in-process): what it reads, the
     * expiries it asks for and what it refuses count for the page around it, as before 0.81 (the
     * page cache middleware, if it stores the nested request itself, begins and records its own as
     * well), and it prints that page's CSP nonce. Whatever the purpose: a rebuild and verify's
     * second render need it like a visit; an audit, GEO or a preview render begins no page and
     * records nothing, so there it changes nothing. A request of its own that is a fragment answers
     * a page of its own, so it keeps its own nonce (or the one it sent back).
     *
     * @return null nothing to undo: the joined collectors are the frame's own slots
     */
    private function joinCallersPage(RenderContext $ctx, RenderFrame $frame): ?\Closure
    {
        $outer = $frame->partOf();
        if ($outer === null) {
            return null;
        }
        if (class_exists(\Preflow\View\NonceGenerator::class) && $this->container->bound(\Preflow\View\NonceGenerator::class) && !$this->answersOwnPage($ctx, $outer)) {
            $this->container->get(\Preflow\View\NonceGenerator::class)->share($outer);
        }
        if ($this->container->has(\Preflow\Core\PageCache\PageCache::class)) {
            $this->container->get(\Preflow\Core\PageCache\DependencyRecorder::class)->nestIn($outer);
            $this->container->get(\Preflow\Core\PageCache\PageCache::class)->nestIn($outer);
        }

        return null;
    }

    /** $ctx renders a request of its own (not $outer's) that is a fragment request. */
    private function answersOwnPage(RenderContext $ctx, RenderFrame $outer): bool
    {
        $request = $ctx->request?->request;
        if ($request === null || $request === $outer->context->request?->request) {
            return false;
        }

        return RenderContext::fromRequest($this->withHypermedia($request), $this->site(), $ctx->clock)->purpose === Purpose::Fragment;
    }

    /**
     * Hands the messages $frame queued through notify (channel 'notify') to $outer, where $frame
     * delivers: a nested request failed, and its frame drops everything else it queued.
     */
    private static function handOnQueuedMail(RenderFrame $frame, RenderFrame $outer): void
    {
        // A frame that never made its outbox has nothing to hand on (and makes none, nor $outer's, now).
        if ($frame->context->outbox === \Preflow\Core\Context\OutboxPolicy::Deliver) {
            $frame->madeOutbox()?->handOn(\Preflow\Core\Context\Bridge\NotifyChannel::NAME, $outer->outbox());
        }
    }

    /**
     * The site this app renders: its origin (app.url, else http://localhost), its locales and
     * default locale and its locale URL rule (the LocaleUrls boot binds, from config/i18n.php).
     * Known before boot() too (the console opens its frame with it).
     *
     * @since 0.81.0
     */
    public function site(): Site
    {
        if ($this->site !== null) {
            return $this->site;
        }
        $urls = $this->container->bound(\Preflow\Core\Http\LocaleUrls::class)
            ? $this->container->get(\Preflow\Core\Http\LocaleUrls::class)
            : \Preflow\Core\Http\LocaleUrls::fromConfig($this->configFile('i18n') === null ? null : (array) $this->configFile('i18n'), (string) $this->config->get('app.locale', 'en'));
        $url = $this->config->get('app.url');
        $origin = is_string($url) && $url !== '' ? rtrim($url, '/') : 'http://localhost';

        return $this->site = new Site($origin, $urls->locales, $urls->default, $urls);
    }

    private ?Site $site = null;

    /**
     * The request's part of the application: hooks, the kernel and its pipeline, the page cache's
     * invalidations, the HEAD body. Runs in the current frame. $clients is $request with its
     * hypermedia clients when the caller made it already (withHypermedia()).
     */
    private function handleInFrame(ServerRequestInterface $request, ?ServerRequestInterface $clients = null): ResponseInterface
    {
        foreach ($this->requestHooks as $hook) {
            $hook($request);
        }

        $internal = \Preflow\Core\Http\InternalRender::is($request);

        // A fragment request whose client sends its page's nonce back is answered with that nonce
        // (so what it puts into the page passes the page's CSP), in this request's frame only.
        if (!$internal) {
            $this->adoptPageNonce($request);
        }
        // The hypermedia clients ride along, for middleware an app builds itself (AuthMiddleware).
        $request = $clients ?? $this->withHypermedia($request);

        try {
            $response = $this->kernel?->handle($request) ?? throw new \RuntimeException('Application not booted. Call boot() first.');
        } catch (\Throwable $e) {
            // What the request wrote may be written (part of it, or by another connection).
            $this->pageChanges?->flush();
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

        return $response;
    }

    /** $request with the hypermedia clients as an attribute (RenderContext::fromRequest() asks them whether it is a fragment). */
    private function withHypermedia(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request->withAttribute(
            \Preflow\Core\Http\Hypermedia\HypermediaProtocols::class,
            $this->container->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class),
        );
    }

    /**
     * The context of a request asked for inside $outer: its own request and purpose
     * (RenderContext::fromRequest(), derived as the request asks: withDerivedContext()), but
     * never looser than $outer. Inside an internal render
     * (Purpose::isInternal()) it takes that render's purpose. It keeps a write policy
     * stricter than Allow (Skip, Refuse), an outbox policy stricter than Deliver (Capture,
     * Discard), a role viewer (with its Skip and Capture) and frozen time (at($outer->now)), so a
     * page's sub-request inside a preview, a view as a role, an audit or a render as of a date
     * writes, sends and is stored no more than the render around it. The clock is $outer's; a
     * console frame on Console's own system clock has none of its own (the app was booted before
     * the command ran), so a command's request takes the app's bound clock; a console context on
     * another clock (a test's FrozenClock) keeps it. It reads what $outer reads (since 0.81): working
     * copies inside an admin request or a preview, the site as of a date inside such a render;
     * a request that should read published inside them renders with Application::render().
     * It keeps a PreviewGrant $outer has (since 0.81.0), so its follow-ups carry the token too.
     */
    private function nestedContext(ServerRequestInterface $request, RenderContext $outer): RenderContext
    {
        $clock = self::onConsoleDefaultClock($outer)
            ? $this->container->get(\Psr\Clock\ClockInterface::class)
            : $outer->clock;
        $ctx = $this->derived(RenderContext::fromRequest($this->withHypermedia($request), $this->site(), $clock), $request);
        if ($outer->purpose->isInternal()) {
            // A request inside the SEO audit, GEO, a rebuild, verify or a preview is part of that
            // render: it leaves no trace where the render leaves none, and the page cache never
            // stores it (a rebuild's purpose without the rebuild's own request is not stored).
            $ctx = $ctx->because($outer->purpose);
        }
        if ($outer->viewer->isSynthetic()) {
            $ctx = $ctx->as($outer->viewer);
        }
        $ctx = match ($outer->writes) {
            \Preflow\Core\Context\WritePolicy::Refuse => $ctx->refusingWrites(),
            \Preflow\Core\Context\WritePolicy::Skip => $ctx->writes === \Preflow\Core\Context\WritePolicy::Refuse ? $ctx : $ctx->skippingWrites(),
            default => $ctx,
        };
        $ctx = match ($outer->outbox) {
            \Preflow\Core\Context\OutboxPolicy::Discard => $ctx->discarding(),
            \Preflow\Core\Context\OutboxPolicy::Capture => $ctx->capturing(),
            default => $ctx,
        };
        $ctx = match (true) {
            $outer->reads->at !== null => $ctx->asOf($outer->reads->at),
            $outer->reads->readsWorkingCopies() => $ctx->workingCopies(),
            default => $ctx,
        };
        // A request inside a render under a preview token renders under it too: what it emits
        // (component URLs) carries the token on.
        $grant = $outer->get(\Preflow\Core\Context\Preview\PreviewGrant::class);
        if ($grant !== null) {
            $ctx = $ctx->with($grant);
        }

        return $outer->isFrozen() ? $ctx->at($outer->now) : $ctx;
    }

    /**
     * A console frame has no clock of its own: Console opens it on the system clock before any app
     * exists. Booted inside one, the app puts its own bound clock on the rest of that frame, so a
     * command that reads Contexts::current()->clock (or Contexts::clock()) gets the clock the app or
     * a test bound. A console context built on another clock, or frozen with at(), keeps its own.
     */
    private function consoleOnTheAppsClock(): void
    {
        if (Contexts::depth() === 0 || !self::onConsoleDefaultClock(Contexts::current())) {
            return;
        }
        $clock = $this->container->get(\Psr\Clock\ClockInterface::class);
        if (!$clock instanceof \Preflow\Core\Context\SystemClock) {
            Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->withClock($clock));
        }
    }

    /** A console context on Console's own system clock, not frozen: it has no clock of its own. */
    private static function onConsoleDefaultClock(RenderContext $ctx): bool
    {
        return $ctx->purpose === Purpose::Console && $ctx->clock instanceof \Preflow\Core\Context\SystemClock && !$ctx->isFrozen();
    }

    /** "Now" of the current render, else the app's clock (a script, the console before its frame). */
    private function clock(): \Psr\Clock\ClockInterface
    {
        return Contexts::depth() > 0 ? Contexts::current()->clock : $this->container->get(\Psr\Clock\ClockInterface::class);
    }

    /**
     * A visitor's request as the outermost render: Fragment or Visit, as its hypermedia client
     * asks, derived as the request asks (withDerivedContext()). $clients is $request with its
     * hypermedia clients (withHypermedia()), made once per request for this and the kernel.
     */
    private function requestContext(ServerRequestInterface $request, ServerRequestInterface $clients): RenderContext
    {
        return $this->derived(
            RenderContext::fromRequest($clients, $this->site(), $this->container->get(\Psr\Clock\ClockInterface::class)),
            $request,
        );
    }

    /**
     * A request failed: what it queued before still goes out (data written before the failure
     * stays written, so its mail must go), its after-response work never runs. Failures here are
     * logged, so they never hide the request's own.
     */
    private function deliverAfterFailure(): void
    {
        $this->logFailure($this->flushDeferredNotifications(...), 'Deferred notifications could not be flushed');
        $this->logFailure(Contexts::deliverQueued(...), 'Queued items could not be sent');
    }

    /** Runs $send; a failure is logged (the response is rendered or sent already), not thrown. */
    private function logFailure(\Closure $send, string $what): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            error_log($what . ': ' . $e->getMessage());
        }
    }

    private bool $afterResponseInHandle = false;

    private bool $rebuildsScheduled = false;

    /** The request's signed component state store (bootComponentLayer()): made on first use, forgotten when a request begins. */
    private ?\Preflow\Components\SignedStateStore $signedStateStore = null;

    /**
     * The page nonce a fragment request sent back, adopted for the rest of the request's frame
     * (untrusted input: a fragment request only, a base64 value of 22+ characters; the page cache
     * refuses to store the response, see bootViewLayer()). The frame's end is where it ends:
     * after-response work and internal renders have frames and nonces of their own.
     */
    private function adoptPageNonce(ServerRequestInterface $request): void
    {
        if (!class_exists(\Preflow\View\NonceGenerator::class) || !$this->container->bound(\Preflow\View\NonceGenerator::class)) {
            return;
        }
        $nonce = $this->container->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class)->pageNonce($request);
        if ($nonce !== null) {
            $this->container->get(\Preflow\View\NonceGenerator::class)->adoptInFrame($nonce);
        }
    }

    /**
     * Runs $work after the response of the current request has gone out, in the request's frame
     * (it sees the request's context; a render it starts gets its own frame): run() runs it once
     * the client is released (fastcgi_finish_request / litespeed_finish_request) and the
     * deferred mail is sent, in the order added. The page cache rebuilds there what the request
     * queued. A closure that throws is logged; the others still run. In-process callers of
     * handle() (commands, tests) have no "after the response": their work is dropped when the
     * request ends, unless runAfterResponseInHandle() is on. Outside every render there is no
     * response to wait for, and $work is dropped. The work lives in the frame's outbox
     * (Contexts::frame()->outbox()->afterResponse()), so a render that captures or discards
     * drops it with the rest of what it queued.
     *
     * @param \Closure(): void $work
     */
    public function afterResponse(\Closure $work): void
    {
        if (Contexts::depth() === 0) {
            return;
        }
        Contexts::frame()->outbox()->afterResponse($work);
    }

    /**
     * Sends what the current request queued and runs the work added with afterResponse() now
     * (what run() does after the response), when the current frame is the outermost one.
     *
     * @internal run(), and tests through runAfterResponseInHandle()
     */
    public function runAfterResponse(): void
    {
        if (Contexts::depth() === 1) {
            $this->logFailure(Contexts::deliverNow(...), 'Queued items could not be sent');
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

    /**
     * A test seam, never for production code: handle() leaves notify's deferred mail of a request
     * it opens itself to the next Notifier::flushDeferred(), as run() sends it only after the
     * response went out, so a test can check what the response said before the mail goes
     * (holdForFlush()). Off again with false; run() also turns it off when it finishes.
     *
     * @since 0.81.0
     * @internal tests only
     */
    public function holdMailForFlush(bool $on = true): void
    {
        $this->emitting = $on;
    }

    /**
     * True while run() handles its request (or a test held the mail, holdMailForFlush()): handle()
     * then leaves notify's deferred mail to run(), which sends it after the response; a request
     * handle() then opens itself leaves its mail to the next flushDeferred() (holdForFlush()).
     */
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
        // The container's current Notifier is used, not a boot-time snapshot: core's queue
        // (NotifyOutbox) sends the outermost frame's mail now, what nested frames queued goes
        // with their frame; a Notifier bound with notify's own queue is flushed as before 0.81.
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
     * Handle request from PHP globals and emit response. The request is the outermost render:
     * run() opens its frame (RenderContext::fromRequest()), emits the response, releases the
     * client, then sends what the request queued and runs its
     * after-response work, in the request's frame. When the request fails, what it queued
     * before still goes out; its after-response work does not run.
     */
    public function run(): void
    {
        if (class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)
            && class_exists(\Nyholm\Psr7Server\ServerRequestCreator::class)) {
            $request = \Preflow\Core\Http\GlobalRequest::create();
            // Built once the request carries its hypermedia clients: a header-only fragment is no Visit.
            $clients = $this->withHypermedia($request);
            $ctx = $this->requestContext($request, $clients);
            $this->rebuildsScheduled = false;
            $this->signedStateStore = null;

            Contexts::run($ctx, function () use ($request, $clients): void {
                // While run() handles the request, a request the site asks for in-process
                // (handle()) leaves the deferred mail to run(), which sends it after the response.
                $this->emitting = true;
                try {
                    $response = $this->handleInFrame($request, $clients);
                    ($this->emitter ?? fn (ResponseInterface $r) => (new Emitter())->emit($r, $request))($response);
                } catch (\Throwable $e) {
                    $this->emitting = false;
                    // The error output must still reach the client: it is not released.
                    $this->deliverAfterFailure();
                    throw $e;
                }
                $this->emitting = false;
                // The deferred mail, then the page cache's rebuilds and other work queued for after the response.
                $this->logFailure(function (): void {
                    $this->finishResponse();
                    $this->flushDeferredNotifications();
                }, 'Deferred notifications could not be flushed');
                $this->runAfterResponse();
            });
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
        // Where the change-date backfill learns old records' dates besides the data layer's own (0.84).
        if (class_exists(\Preflow\Data\ChangeDateSources::class)) {
            $this->container->instance(\Preflow\Data\ChangeDateSources::class, new \Preflow\Data\ChangeDateSources());
        }
        // The tables migrations make, with each column's intent (text or exact), for `preflow migrate`
        // to give existing ones the text rule (data 0.78): the framework's own here, packages' in theirs.
        if (class_exists(\Preflow\Data\Schema\DeclaredTables::class)) {
            $declared = new \Preflow\Data\Schema\DeclaredTables();
            $exact = \Preflow\Data\Migration\MigrationColumns::EXACT;
            $declared->add('preflow_migrations', ['migration' => $exact, 'ran_at' => $exact]);
            $declared->add(\Preflow\Data\Migration\MigrationColumns::TABLE, ['id' => $exact, 'table_name' => $exact, 'column_name' => $exact, 'intent' => $exact]);
            $declared->add('preflow_throttle', ['id' => $exact, 'purpose' => $exact]);
            if (class_exists(\Preflow\Auth\Tokens\Tokens::class)) {
                $declared->add('auth_tokens', ['id' => $exact, 'purpose' => $exact, 'subject' => $exact]);
                $declared->add('auth_remember', ['id' => $exact, 'guard' => $exact, 'user_id' => $exact, 'validator_hash' => $exact, 'previous_hash' => $exact]);
            }
            $this->container->instance(\Preflow\Data\Schema\DeclaredTables::class, $declared);
        }

        // The one description of every type and field (0.93), for an API's schema builder: bound
        // without config/data.php too, so packages always add their parts to it. Without a type
        // registry (no model files, no model classes) it reads the app's model folder: no types.
        if (class_exists(\Preflow\Data\Describe\Description::class)) {
            $this->container->singleton(\Preflow\Data\Describe\DescriptionContributors::class, static fn (): \Preflow\Data\Describe\DescriptionContributors => new \Preflow\Data\Describe\DescriptionContributors());
            $modelsDir = $this->basePath('config/models');
            $this->container->singleton(\Preflow\Data\Describe\Description::class, static fn (Container $c): \Preflow\Data\Describe\Description => new \Preflow\Data\Describe\Description(
                $c->bound(\Preflow\Data\TypeRegistry::class)
                    ? $c->get(\Preflow\Data\TypeRegistry::class)
                    : (($c->bound(\Preflow\Data\DataManager::class) ? $c->get(\Preflow\Data\DataManager::class)->getTypeRegistry() : null)
                        ?? new \Preflow\Data\TypeRegistry($modelsDir, $c->get(\Preflow\Data\Locales::class))),
                $c->bound(\Preflow\Data\DataManager::class) ? $c->get(\Preflow\Data\DataManager::class)->fieldKinds() : new \Preflow\Data\FieldKinds(),
                $c->get(\Preflow\Data\Describe\DescriptionContributors::class),
            ));
        }
        // The one read class and the one write class of every type (0.93) and the packages' output
        // and input parts: the parts are bound without config/data.php too, so packages always add
        // theirs; reading and writing need the DataManager.
        if (class_exists(\Preflow\Data\Records\RecordOutput::class)) {
            $this->container->singleton(\Preflow\Data\Records\RecordParts::class, static fn (): \Preflow\Data\Records\RecordParts => new \Preflow\Data\Records\RecordParts());
            $this->container->singleton(\Preflow\Data\Records\RecordOutput::class, static fn (Container $c): \Preflow\Data\Records\RecordOutput => new \Preflow\Data\Records\RecordOutput(
                $c->get(\Preflow\Data\DataManager::class),
                $c->get(\Preflow\Data\Records\RecordParts::class),
            ));
            $this->container->singleton(\Preflow\Data\Records\RecordInput::class, static fn (Container $c): \Preflow\Data\Records\RecordInput => new \Preflow\Data\Records\RecordInput(
                $c->get(\Preflow\Data\DataManager::class),
                $c->get(\Preflow\Data\Records\RecordParts::class),
                $c->bound(\Preflow\Validation\ValidatorFactory::class) ? $c->get(\Preflow\Validation\ValidatorFactory::class) : new \Preflow\Validation\ValidatorFactory(new \Preflow\Validation\RuleFactory()),
            ));
        }

        $dataConfig = $this->configFile('data');
        if ($dataConfig === null) {
            return;
        }
        $dataConfig = is_array($dataConfig) ? $dataConfig : [];
        $drivers = [];
        $jsonKeys = [];

        foreach ($dataConfig['drivers'] ?? [] as $name => $driverConfig) {
            $driverConfig = is_array($driverConfig) ? $driverConfig : [];
            // The entry's 'driver' (a built-in name or class, or a StorageDriver class), else its name.
            $kind = $driverConfig['driver'] ?? $name;
            $kind = is_string($kind) ? (self::BUILT_IN_STORAGE[$kind] ?? $kind) : '';
            $drivers[$name] = match ($kind) {
                'sqlite' => $this->createSqliteDriver($driverConfig, $collector),
                'mysql' => $this->createMysqlDriver($driverConfig, $collector),
                'json' => $jsonDriver = new \Preflow\Data\Driver\JsonFileDriver(
                    $driverConfig['path'] ?? $this->basePath('storage/data'),
                    // 'cache' => false: no read cache, for files that change outside the app (a git pull).
                    cacheDir: ($driverConfig['cache'] ?? true) === false ? null : $this->dataCachePath(),
                ),
                default => $this->createStorageDriver((string) $name, $kind, $driverConfig),
            };

            if ($kind === 'json') {
                $jsonKeys[] = 'json-*-' . $jsonDriver->pathKey() . '.gen';
            }
        }

        // One cache source for every JSON storage (they share the folder).
        if ($jsonKeys !== []) {
            $this->container->get(CacheRegistry::class)->register(new DirectoryCache(
                'data.json',
                'JSON storage query cache',
                $this->dataCachePath(absolute: true),
                // Every path key's .json caches and leftover .tmp-* files, plus the .gen files of
                // other data paths (old test runs, moved sites). This app's own .gen files stay:
                // they guard a cache write racing a save.
                pattern: 'json-*',
                exclude: count($jsonKeys) === 1 ? $jsonKeys[0] : array_values(array_unique($jsonKeys)),
            ));
        }

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
            $typeRegistry = new \Preflow\Data\TypeRegistry($modelsPath, $locales, $classes, $this->manifest);
            $this->container->instance(\Preflow\Data\TypeRegistry::class, $typeRegistry);
        }

        // Validation (bootValidators(), with or without config/data.php): models validate on save.
        $validatorFactory = $this->container->bound(\Preflow\Validation\ValidatorFactory::class)
            ? $this->container->get(\Preflow\Validation\ValidatorFactory::class)
            : null;

        $dataManager = new \Preflow\Data\DataManager($drivers, 'default', $typeRegistry, $validatorFactory);
        $this->container->instance(\Preflow\Data\DataManager::class, $dataManager);
        // Messages that name a field use the bound type labels (Folio's display labels), resolved on use (0.92).
        $container = $this->container;
        $dataManager->labelsFrom(static fn (): ?\Preflow\Data\TypeLabels => $container->bound(\Preflow\Data\TypeLabels::class) ? $container->get(\Preflow\Data\TypeLabels::class) : null);
        // Rich text is cleaned on every write with the bound HtmlCleaner (a site's own), else the core's (0.93).
        $dataManager->htmlCleanerFrom(static fn (): ?\Preflow\Data\Html\HtmlCleaner => $container->bound(\Preflow\Data\Html\HtmlCleaner::class) ? $container->get(\Preflow\Data\Html\HtmlCleaner::class) : null);
        // Cross-type record search (the admin's palette, a site's own search box); built on first use.
        $this->container->singleton(
            \Preflow\Data\Search\RecordSearch::class,
            static fn (): \Preflow\Data\Search\RecordSearch => new \Preflow\Data\Search\RecordSearch($dataManager),
        );
        $this->bindActivityLog($dataManager, isset($drivers[\Preflow\Data\Activity\StoredActivityLog::STORAGE]) ? \Preflow\Data\Activity\StoredActivityLog::STORAGE : 'default');
        $this->registerTextRuleSteps($dataManager, $migrations, $dataConfig['text_rule'] ?? [], $modelPaths);
        $this->registerChangeDateStep($dataManager, $migrations);
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

    /**
     * The migrate steps of the text rule (data 0.78, the data README's "Existing tables (`preflow
     * migrate`)"): `data.text-rule` gives the declared tables (DeclaredTables: the framework's own,
     * declared here and by packages) and the tables migrations made since 0.78 (MigrationColumns)
     * the collation of each column's intent on the storage the migrations run on, lists the tables
     * without known intent once, and rebuilds SQLite's text indexes when the rule's version
     * changed; `data.text-duplicates` lists the case-variant duplicates of unique text fields no
     * index holds (JSON). The record tables' own columns are converted by their sync steps
     * (RecordTableSync). Every conversion also makes the rule's collation the table default.
     * `data.collations`, after every other step, lists as warnings each text column of a table
     * Preflow manages that still compares otherwise (`db:check` lists them too). `migrate --pretend` runs the check `data.text-rule` (every refusal, nothing
     * changed). config/data.php 'text_rule' => ['skip' => [table, …]] leaves tables alone.
     *
     * @param mixed $config config/data.php 'text_rule'
     */
    /**
     * @param list<string> $modelPaths data.model_paths: where `data.model-exactness` finds the plain
     *        model classes whose #[Field(exact)] it compares with their migrations' columns
     */
    private function registerTextRuleSteps(\Preflow\Data\DataManager $dataManager, \Preflow\Data\Migration\MigrationRegistry $migrations, mixed $config, array $modelPaths = []): void
    {
        if (!class_exists(\Preflow\Data\Schema\TextRuleConversion::class)) {
            return;
        }
        $skip = is_array($config) ? ($config['skip'] ?? []) : null;
        if (!is_array($skip) || !array_is_list($skip) || array_filter($skip, static fn (mixed $table): bool => !is_string($table)) !== []
            || array_diff(array_keys(is_array($config) ? $config : []), ['skip']) !== []) {
            throw new \RuntimeException("config/data.php: 'text_rule' takes ['skip' => [table names]].");
        }
        $container = $this->container;
        $conversion = static fn (): \Preflow\Data\Schema\TextRuleConversion => new \Preflow\Data\Schema\TextRuleConversion(
            $dataManager,
            $container->bound(\Preflow\Data\Schema\RecordTables::class) ? $container->get(\Preflow\Data\Schema\RecordTables::class) : null,
            $container->bound(\Preflow\Data\Schema\DeclaredTables::class) ? $container->get(\Preflow\Data\Schema\DeclaredTables::class) : null,
            $migrations->storage(),
            $skip,
        );
        // `preflow db:check` lists what still compares otherwise (TextRuleConversion::unconverted()).
        $container->bind(\Preflow\Data\Schema\TextRuleConversion::class, $conversion);
        $migrations->afterMigrate('data.text-rule', static fn (): array => $conversion()->convert());
        $migrations->afterMigrate('data.text-duplicates', static fn (): array => $conversion()->duplicates(), sqlOnly: false);
        // After every package's table step: any text column of a Preflow table still on an older collation, as a warning.
        $migrations->afterMigrate('data.collations', static fn (): array => $conversion()->unconverted(), last: true);
        $migrations->check('data.text-rule', static fn (): array => $conversion()->check(), sqlOnly: false);
        if (class_exists(\Preflow\Data\Schema\ModelExactness::class)) {
            $migrations->afterMigrate('data.model-exactness', static fn (): array => (new \Preflow\Data\Schema\ModelExactness(
                $dataManager,
                (new \Preflow\Data\ModelClasses($modelPaths))->plain(),
                $migrations->storage(),
            ))->check());
        }
    }

    /**
     * `data.change-dates` (since 0.84.0): after every table step has made the change-date columns,
     * every record of a type (the registered record tables, the registry's types) without a change
     * date gets one (ChangeDateBackfill: the activity log's newest event, the type's own updated
     * time, the sources packages add to ChangeDateSources, the storage's write time, the creation
     * time, else now). On every storage; a second run writes nothing; a table it cannot finish is a
     * "refused:" line, so migrate exits 1.
     */
    private function registerChangeDateStep(\Preflow\Data\DataManager $dataManager, \Preflow\Data\Migration\MigrationRegistry $migrations): void
    {
        if (!class_exists(\Preflow\Data\ChangeDateBackfill::class)) {
            return;
        }
        $container = $this->container;
        $types = static function () use ($container, $dataManager): array {
            $defs = [];
            if ($container->bound(\Preflow\Data\Schema\RecordTables::class)) {
                foreach ($container->get(\Preflow\Data\Schema\RecordTables::class)->all() as $entry) {
                    $defs[] = $entry['def'];
                }
            }
            $registry = $dataManager->getTypeRegistry();
            foreach ($registry?->keys() ?? [] as $key) {
                $defs[] = $registry->get($key);
            }

            return $defs;
        };
        $migrations->afterMigrate('data.change-dates', static function () use ($container, $dataManager, $types): array {
            $log = $container->bound(\Preflow\Core\Activity\ActivityLog::class) ? $container->get(\Preflow\Core\Activity\ActivityLog::class) : null;

            $sources = $container->bound(\Preflow\Data\ChangeDateSources::class) ? $container->get(\Preflow\Data\ChangeDateSources::class)->all() : [];

            return (new \Preflow\Data\ChangeDateBackfill($dataManager, $types, $log instanceof \Preflow\Core\Activity\LastChanges ? $log : null, $sources))->run();
        }, sqlOnly: false, last: true);
    }

    /**
     * The stored activity log (tables preflow_activity and preflow_activity_last, on the storage
     * alias "activity" when the site has one), config/activity.php "keep_days" and "keep_rows";
     * `preflow migrate` creates its tables (the step "activity.table"), `preflow activity:prune`
     * applies the retention.
     */
    private function bindActivityLog(\Preflow\Data\DataManager $dataManager, string $storage): void
    {
        if (!class_exists(\Preflow\Data\Activity\StoredActivityLog::class)) {
            return;
        }
        $config = $this->configFile('activity');
        $config = is_array($config) ? $config : [];
        $keepDays = $config['keep_days'] ?? \Preflow\Data\Activity\StoredActivityLog::KEEP_DAYS;
        $keepRows = $config['keep_rows'] ?? \Preflow\Data\Activity\StoredActivityLog::KEEP_ROWS;
        if (!is_int($keepDays) || !is_int($keepRows)) {
            throw new \RuntimeException('config/activity.php: keep_days and keep_rows must be whole numbers (0 keeps all).');
        }
        $container = $this->container;
        $container->singleton(
            \Preflow\Data\Activity\StoredActivityLog::class,
            static fn (): \Preflow\Data\Activity\StoredActivityLog => new \Preflow\Data\Activity\StoredActivityLog(
                $dataManager,
                $storage,
                $keepDays,
                $keepRows,
                logger: interface_exists(\Psr\Log\LoggerInterface::class) && $container->bound(\Psr\Log\LoggerInterface::class) ? $container->get(\Psr\Log\LoggerInterface::class) : null,
            ),
        );
        $container->singleton(
            \Preflow\Core\Activity\ActivityLog::class,
            static fn (): \Preflow\Core\Activity\ActivityLog => $container->get(\Preflow\Data\Activity\StoredActivityLog::class),
        );
        $tables = static fn (): array => $container->get(\Preflow\Data\Activity\StoredActivityLog::class)->tables();
        if ($container->bound(\Preflow\Data\Schema\RecordTables::class)) {
            $container->get(\Preflow\Data\Schema\RecordTables::class)->add('activity', $tables);
        }
        $container->get(\Preflow\Data\Migration\MigrationRegistry::class)->afterMigrate(
            'activity.table',
            static fn (): array => (new \Preflow\Data\Schema\RecordTableSync($dataManager))->syncAll($tables()),
        );
    }

    private function createSqliteDriver(array $config, ?DebugCollector $collector = null): \Preflow\Data\Driver\SqliteDriver
    {
        $path = $config['path'] ?? $this->basePath('storage/data/app.sqlite');
        $dbDir = dirname($path);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        $dsn = str_starts_with($path, 'sqlite:') ? $path : 'sqlite:' . $path;
        $driver = new \Preflow\Data\Driver\SqliteDriver(static fn (): \PDO => \PDO::connect($dsn), $collector);
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

    /** The built-in storage drivers by the names and classes config/data.php may give them. */
    private const array BUILT_IN_STORAGE = [
        'sqlite' => 'sqlite',
        'mysql' => 'mysql',
        'json' => 'json',
        \Preflow\Data\Driver\SqliteDriver::class => 'sqlite',
        \Preflow\Data\Driver\MysqlDriver::class => 'mysql',
        \Preflow\Data\Driver\JsonFileDriver::class => 'json',
    ];

    /**
     * A storage driver config/data.php names by class: built by the container on first use (the
     * DataManager resolves the closure), the entry's other keys passed as the constructor's
     * parameters by name (the rest are autowired). The class and the keys are checked now.
     *
     * @param array<string, mixed> $config
     */
    private function createStorageDriver(string $name, string $class, array $config): \Closure
    {
        $where = "drivers.{$name} in config/data.php";
        if ($class === '' || !class_exists($class)) {
            throw new \RuntimeException(sprintf(
                'Unknown storage driver "%s" for %s. Use sqlite, mysql or json (as the entry\'s name or its \'driver\'),'
                . ' or \'driver\' => the class of a %s.',
                $class,
                $where,
                \Preflow\Data\StorageDriver::class,
            ));
        }
        if (!is_subclass_of($class, \Preflow\Data\StorageDriver::class)) {
            throw new \RuntimeException(sprintf('Storage driver %s for %s does not implement %s.', $class, $where, \Preflow\Data\StorageDriver::class));
        }
        unset($config['driver']);
        $parameters = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [],
        );
        foreach (array_keys($config) as $option) {
            if (!in_array($option, $parameters, true)) {
                throw new \RuntimeException(sprintf(
                    'Storage driver %s for %s has no constructor parameter "%s" (it takes: %s).',
                    $class,
                    $where,
                    $option,
                    $parameters === [] ? 'nothing' : implode(', ', $parameters),
                ));
            }
        }

        // Built on first use (after the providers ran), so its constructor may take services a provider binds.
        $container = $this->container;

        return static fn (): \Preflow\Data\StorageDriver => $container->make($class, $config);
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
        // A response answered with a nonce the client sent (a fragment's page nonce) is never stored:
        // the cache would turn that client-chosen value into a placeholder in the stored body.
        $this->container->get(\Preflow\Core\PageCache\PageCache::class)->refuseWhen('page-nonce', static fn (): bool => $nonce->adopted());

        // Markdown declared with {% apply geo %} / @geo; records only while GEO captures
        $geo = new \Preflow\View\Geo\GeoCollector();
        $this->container->instance(\Preflow\View\Geo\GeoCollector::class, $geo);
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
        // A header the page's response carries: {{ response_header('Cache-Control', 'no-store') }}
        // (the page cache never stores a no-store page). Prints nothing.
        $hints = $this->container->get(\Preflow\Core\Http\ResponseHeaderHints::class);
        $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
            name: 'response_header',
            callable: static function (string $name, string $value) use ($hints): string {
                $hints->set($name, $value);

                return '';
            },
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

        $this->securityHeaders = new \Preflow\Core\Http\SecurityHeadersMiddleware($this->nonceProvider(), $config, $debug->isDebug(), $this->container->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class));
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
        if ($name === 'twig') {
            if (!class_exists(\Preflow\Twig\TwigEngine::class)) {
                return null;
            }
            $context = $this->engineContext($templateDirs, $assets, $debug, $this->twigCachePath(), $geo, $seo);

            return new \Preflow\Twig\TwigEngine(
                templateDirs: $context->templateDirs,
                assetCollector: $context->assets,
                debug: $context->debug,
                cachePath: $context->cachePath,
                geoCollector: $context->geo,
                seoContext: $context->seo,
                liveParts: $context->liveParts,
                pageCache: $context->pageCache,
            );
        }
        // Any other engine keeps the functions and globals registered on it, so a package can give
        // them to an engine of its own (Folio renders its Twig templates so).
        $engine = $name === 'blade'
            ? (class_exists(\Preflow\Blade\BladeEngine::class) ? $this->createBladeEngine($templateDirs, $assets, $debug, $geo, $seo) : null)
            : $this->createFactoryEngine($name, $templateDirs, $assets, $debug, $geo, $seo);

        return $engine === null ? null : new \Preflow\View\SharingTemplateEngine($engine);
    }

    /**
     * What the app's engine is built from, bound in the container as TemplateEngineContext (for
     * every engine), so a package can build another engine like it.
     *
     * @param list<string> $templateDirs
     */
    private function engineContext(
        array $templateDirs,
        \Preflow\View\AssetCollector $assets,
        DebugLevel $debug,
        string $cachePath,
        ?\Preflow\View\Geo\GeoCollector $geo,
        ?\Preflow\View\Seo\SeoContext $seo,
    ): \Preflow\View\TemplateEngineContext {
        $context = new \Preflow\View\TemplateEngineContext(
            templateDirs: $templateDirs,
            assets: $assets,
            debug: $debug->isDebug(),
            cachePath: $cachePath,
            geo: $geo,
            seo: $seo,
            liveParts: $this->container->get(\Preflow\Core\PageCache\LiveParts::class),
            pageCache: $this->container->get(\Preflow\Core\PageCache\PageCache::class),
        );
        $this->container->instance(\Preflow\View\TemplateEngineContext::class, $context);

        return $context;
    }

    /**
     * The engine of the TemplateEngineFactory app.engine names, built on its first render (a
     * LazyTemplateEngine stands in until then, so the factory may take services a provider
     * binds): the factory comes from the container and gets what the built-in engines get, plus
     * storage/cache/templates for compiled templates (registered, so cache:clear empties it).
     */
    private function createFactoryEngine(
        string $class,
        array $templateDirs,
        \Preflow\View\AssetCollector $assets,
        DebugLevel $debug,
        ?\Preflow\View\Geo\GeoCollector $geo = null,
        ?\Preflow\View\Seo\SeoContext $seo = null,
    ): \Preflow\View\TemplateEngineInterface {
        if (!class_exists($class)) {
            throw new \RuntimeException(sprintf(
                "Unknown template engine: %s. Use 'twig', 'blade' or the class name of a %s.",
                $class,
                \Preflow\View\TemplateEngineFactory::class,
            ));
        }
        if (!is_subclass_of($class, \Preflow\View\TemplateEngineFactory::class)) {
            throw new \RuntimeException(sprintf('Template engine %s (app.engine) does not implement %s.', $class, \Preflow\View\TemplateEngineFactory::class));
        }
        $cachePath = $this->absolutePath('storage/cache/templates');
        $this->container->get(CacheRegistry::class)->register(new DirectoryCache(
            'templates',
            'Compiled templates',
            $cachePath,
        ));

        $context = $this->engineContext($templateDirs, $assets, $debug, $cachePath, $geo, $seo);
        $container = $this->container;

        return new \Preflow\View\LazyTemplateEngine(
            static function () use ($container, $class, $context): \Preflow\View\TemplateEngineInterface {
                /** @var \Preflow\View\TemplateEngineFactory $factory */
                $factory = $container->get($class);

                return $factory->create($context);
            },
            $class::templateExtension(),
        );
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
        if (Contexts::depth() > 0 && Contexts::current()->writes === \Preflow\Core\Context\WritePolicy::Refuse) {
            // A frame that refuses writes (a follow-up under a preview token) never schedules them:
            // the rebuild would run clamped to Refuse and fail on a page that writes on GET.
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

    /**
     * Renders a rebuild request (Rebuild::request()) through the whole app with render(), as an
     * internal Rebuild render of its path in its locale (the URL's prefix, else the locale the
     * page was stored under): an anonymous visitor's clean GET in a frame of its own, so nothing
     * of the request that queued it (its assets, users, cookies, superglobals) reaches the page,
     * and nothing it reads is recorded for the page around it (recordings belong to the frame).
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

        return $this->renderAsRebuild($request);
    }

    /** $request as the internal Rebuild render renderRebuild() makes of it (also for a Cacheability probe). */
    private function renderAsRebuild(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->site();
        $path = $request->getUri()->getPath();
        $path = $path === '' ? '/' : $path;
        $locale = $site->urls?->localeOf($path) ?? $request->getHeaderLine('Accept-Language');
        $ctx = RenderContext::internal(Purpose::Rebuild, $site, $this->clock(), $path)
            ->in(in_array($locale, $site->locales, true) ? $locale : $site->defaultLocale)
            ->for($request);

        return $this->render($ctx, $request)->response;
    }

    /**
     * Said once, at boot, in development: what an app.key that cannot sign (AppKey::canSign())
     * switches off. To the site's logger when one is bound, else PHP's error log; not to a console's
     * stderr without a log file (a command's output, a test run).
     */
    private function reportWeakKey(): void
    {
        $message = 'app.key cannot sign (shorter than 32 bytes, or a public example key): page-cache storing and live-part signing, preview tokens (interactive parts of a preview run live) and share-preview links are off.';
        if ($this->container->bound(\Psr\Log\LoggerInterface::class)) {
            $this->container->get(\Psr\Log\LoggerInterface::class)->warning($message);
        } elseif (PHP_SAPI !== 'cli' || (string) ini_get('error_log') !== '') {
            error_log($message);
        }
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
        if (!$config->enabled || $this->pageChanges === null) {
            return;
        }
        // In debug mode the cache does not serve or store, but the middleware stays in the pipeline
        // for Cacheability's probes (`pages:why`) only: it passes every other request on, rebuilds included.
        $probeOnly = $debug->isDebug();
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
            localePrefix: self::carriedLocalePrefix(...),
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
            applyPending: static fn (): bool => $container->get(\Preflow\Core\PageCache\Refresher::class)->applyPending(),
            rebuildOnly: $this->runningInConsole(),
            probeOnly: $probeOnly,
            hypermedia: $this->container->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class),
            // Verify's second render: in a frame of its own as render() opens one (its own page
            // meta and nonce, the assets boot registered, nobody signed in, its queued mail
            // dropped), under the Verify context the middleware gives.
            render: fn (RenderContext $ctx, ServerRequestInterface $request, \Closure $handle): RenderResult => $this->renderWith($ctx, $this->withSiteDefaults($handle)),
            // Headers live parts add while the served page is filled (never on the stored page).
            hints: $container->get(\Preflow\Core\Http\ResponseHeaderHints::class),
            // A cookie a render left queued keeps its page out of the store (since 0.81.1).
            cookies: $container->get(\Preflow\Core\Http\CookieQueue::class),
        );
        if (!$probeOnly) {
            $this->container->instance(\Preflow\Core\PageCache\PageCacheMiddleware::class, $middleware);
        }
        $this->addMiddleware($middleware);
        // pages:why and the page-cache test assertion: why a page would not be stored, in any mode.
        $origin = $this->config->get('app.url');
        $this->container->singleton(
            \Preflow\Core\PageCache\Cacheability::class,
            fn (): \Preflow\Core\PageCache\Cacheability => new \Preflow\Core\PageCache\Cacheability(
                $this->renderAsRebuild(...),
                is_string($origin) && $origin !== '' ? $origin : null,
                fn (): array => $this->site()->locales,
            ),
        );
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

        // Registered once, however often the packages are read (installedPackages() is public).
        $caches = $this->container->get(CacheRegistry::class);
        if ($caches->get('composer') === null) {
            $caches->register(new DirectoryCache('composer', 'Decoded composer installed.json', $path));
        }

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

        $context = $this->engineContext($templateDirs, $assets, $debug, $cachePath, $geo, $seo);

        return new \Preflow\Blade\BladeEngine(
            templateDirs: $context->templateDirs,
            assetCollector: $context->assets,
            debug: $context->debug,
            cachePath: $context->cachePath,
            geoCollector: $context->geo,
            seoContext: $context->seo,
            liveParts: $context->liveParts,
            pageCache: $context->pageCache,
        );
    }

    /**
     * RuleFactory (with the rules of app/Rules/) and ValidatorFactory, whenever preflow/validation is
     * installed: an app validates forms without a database (config/data.php) too. Since 0.66; before,
     * only the data layer bound them.
     */
    private function bootValidators(): void
    {
        if (!class_exists(\Preflow\Validation\RuleFactory::class)) {
            return;
        }
        $ruleFactory = new \Preflow\Validation\RuleFactory();

        // Auto-discover custom rules from app/Rules/
        $rulesPath = $this->basePath('app/Rules');
        if (is_dir($rulesPath)) {
            $ruleClasses = [];
            foreach (glob($rulesPath . '/*.php') ?: [] as $file) {
                $class = 'App\\Rules\\' . pathinfo($file, PATHINFO_FILENAME);
                if (class_exists($class)) {
                    $ruleClasses[] = $class;
                }
            }
            if ($ruleClasses !== []) {
                $ruleFactory->discover($ruleClasses);
            }
        }

        $this->container->instance(\Preflow\Validation\RuleFactory::class, $ruleFactory);
        // Inside a render the forms answer in the translator's language, which follows the render
        // context: the visitor's locale on a public page, the interface language on the admin
        // surface (since 0.84.2 for a site's own forms too). Outside every render: English.
        // A factory the site bound before boot() stays (the one way to keep English messages, or any
        // other translator, for the data layer's model validation too: it takes the factory at boot).
        if ($this->container->bound(\Preflow\Validation\ValidatorFactory::class)) {
            return;
        }
        $container = $this->container;
        $this->container->instance(\Preflow\Validation\ValidatorFactory::class, new \Preflow\Validation\ValidatorFactory(
            $ruleFactory,
            contextTranslator: static fn (): ?\Preflow\I18n\Translator => Contexts::peek() !== null && $container->bound(\Preflow\I18n\Translator::class)
                ? $container->get(\Preflow\I18n\Translator::class)
                : null,
        ));
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
        // Components with #[State] keep it in the signed store (with the key the action tokens
        // use), or in the store #[StateIn] names, from the container (the session store needs the
        // session). The signed store is one per request: it issues all its strings at its first,
        // so a page rendered twice in a request (the page cache's verify) carries the same state.
        $renderer->enableState(
            function (string $store) use ($container, $secretKey): \Preflow\Components\StateStore {
                if ($store === \Preflow\Components\SignedStateStore::class) {
                    return $this->signedStateStore ??= new \Preflow\Components\SignedStateStore($secretKey);
                }
                $made = $container->has($store) ? $container->get($store) : $container->make($store);
                if (!$made instanceof \Preflow\Components\StateStore) {
                    throw new \RuntimeException(sprintf('The container gave %s for the state store %s, which is no %s.', get_debug_type($made), $store, \Preflow\Components\StateStore::class));
                }

                return $made;
            },
            $debug->isDebug(),
        );

        // Register component template functions
        $componentProvider = new \Preflow\Components\ComponentsExtensionProvider(
            $renderer, $componentMap, $componentFactory
        );
        $this->registerExtensionProvider($engine, $componentProvider);

        // Hypermedia driver: htmx, or the class app.hypermedia names (built by the container)
        if (class_exists(\Preflow\Htmx\HtmxDriver::class)) {
            $componentToken = new \Preflow\Htmx\ComponentToken($secretKey);
            $this->container->instance(\Preflow\Htmx\ComponentToken::class, $componentToken);
            $driver = $this->createHypermediaDriver($debug->isDebug());
            $this->container->instance(\Preflow\Htmx\HypermediaDriver::class, $driver);
            if ($driver instanceof \Preflow\Htmx\HtmxDriver) {
                $this->container->instance(\Preflow\Htmx\HtmxDriver::class, $driver);
            }
            // First of the clients core asks about requests (auth, CSRF, security headers, page cache, GEO)
            $this->container->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class)->add($driver);
            // Form helpers write the driver's attributes when it has form attributes
            if ($driver instanceof \Preflow\View\FormHypermediaDriver) {
                $this->container->instance(\Preflow\View\FormHypermediaDriver::class, $driver);
            }

            // htmx itself: vendored and inlined (no external request). Included
            // always (default), on demand (when a template uses hd.*), or never.
            // Another driver brings its own client (assetTag(), or its templates).
            $htmxAssets = null;
            if ($driver instanceof \Preflow\Htmx\HtmxDriver) {
                $assets = $this->container->get(\Preflow\View\AssetCollector::class);
                $htmxConfig = (array) ($this->configFile('htmx') ?? []);
                $htmxAssets = new \Preflow\Htmx\HtmxAssets($assets, (string) ($htmxConfig['include'] ?? 'always'));
                $this->container->instance(\Preflow\Htmx\HtmxAssets::class, $htmxAssets);
                $driver->useAssets($htmxAssets); // form helpers (form_begin, inline validation) need htmx too
                if ($htmxAssets->mode() === 'always') {
                    $htmxAssets->require();
                }
            }

            // A component with #[State] carries it inside its wrapper, in the driver's carrier
            // (DrivesComponentActions; a driver built later is asked on the first such render).
            $renderer->carryStateWith(static function (string $componentId, string $carried) use ($driver): string {
                $actions = $driver instanceof \Preflow\Htmx\LazyHypermediaDriver ? $driver->driver() : $driver;

                return $actions instanceof \Preflow\Core\Http\Hypermedia\DrivesComponentActions
                    ? $actions->stateCarrier($componentId, $carried)
                    : '';
            });

            // Register hd template functions
            // On a /de/… page component URLs are /de/--component/…, so actions run in that
            // locale: the prefix the current render's URL carried.
            $container = $this->container;
            // The short call (hd.post('add', values, options)) asks the renderer which component
            // renders; in debug its misused options fail, otherwise they go to the site's logger.
            $htmxProvider = new \Preflow\Htmx\HtmxExtensionProvider(
                $driver,
                $componentToken,
                assets: $htmxAssets,
                pathPrefix: self::carriedLocalePrefix(...),
                renderer: $renderer,
                debug: $debug->isDebug(),
                log: $this->pageCacheLogger(),
            );
            $this->registerExtensionProvider($engine, $htmxProvider);

            // Component endpoint
            $endpoint = new \Preflow\Htmx\ComponentEndpoint(
                token: $componentToken,
                renderer: $renderer,
                driver: $driver,
                componentFactory: function (string $class, array $props) use ($container) {
                    $component = $container->has($class) ? $container->get($class) : $container->make($class);
                    $component->setProps($props);
                    return $component;
                },
                assetCollector: $this->container->get(\Preflow\View\AssetCollector::class),
                debug: $debug->isDebug(),
            );
            $this->container->instance('preflow.component_endpoint', $endpoint);
            $this->container->instance(\Preflow\Htmx\ComponentEndpoint::class, $endpoint);
        }
    }

    /**
     * The app's hypermedia driver: HtmxDriver (with the ResponseHeaders it records into, the debug
     * flag and the site's logger), or the
     * HypermediaDriver class config/app.php 'hypermedia' names, built by the container.
     */
    private function createHypermediaDriver(bool $debug): \Preflow\Htmx\HypermediaDriver
    {
        $class = $this->config->get('app.hypermedia') ?: \Preflow\Htmx\HtmxDriver::class; // unset, null or '': htmx
        if ($class === \Preflow\Htmx\HtmxDriver::class) {
            $responseHeaders = new \Preflow\Htmx\ResponseHeaders();
            $this->container->instance(\Preflow\Htmx\ResponseHeaders::class, $responseHeaders);

            // In debug, misused action options fail and other drivers' option keys are warned about (to the site's logger).
            return new \Preflow\Htmx\HtmxDriver($responseHeaders, $debug, $this->pageCacheLogger());
        }
        if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, \Preflow\Htmx\HypermediaDriver::class)) {
            throw new \RuntimeException(sprintf(
                'Unknown hypermedia driver: %s. Set app.hypermedia to the class name of a %s (default: %s).',
                is_string($class) ? $class : get_debug_type($class),
                \Preflow\Htmx\HypermediaDriver::class,
                \Preflow\Htmx\HtmxDriver::class,
            ));
        }

        // Built on first use (after the providers ran), shared: the container's $class (a
        // provider may bind it), so a component that type-hints the class gets the same one.
        $container = $this->container;
        $container->singleton($class);
        $resolve = static function () use ($container, $class): \Preflow\Htmx\HypermediaDriver {
            $driver = $container->get($class);
            if (!$driver instanceof \Preflow\Htmx\HypermediaDriver) {
                throw new \RuntimeException(sprintf('app.hypermedia: the container gave %s for %s, which is no HypermediaDriver.', get_debug_type($driver), $class));
            }

            return $driver;
        };

        return is_subclass_of($class, \Preflow\View\FormHypermediaDriver::class)
            ? new \Preflow\Htmx\LazyFormHypermediaDriver($resolve, $class)
            : new \Preflow\Htmx\LazyHypermediaDriver($resolve, $class);
    }

    private function bootRouting(): void
    {
        if (!class_exists(\Preflow\Routing\Router::class)) {
            return;
        }

        // What preflow/routing sets up itself (its Redirect\PageExists is a page lookup source), with a
        // router of the app's own too.
        \Preflow\Routing\RoutingRegistration::register($this);

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
        // The one rule for locale URL prefixes, bound for every site (a single locale without
        // config/i18n.php), with or without preflow/i18n. Its options are checked here: a wrong
        // value stops the boot instead of doing nothing.
        $i18nFile = $this->configFile('i18n');
        $i18nConfig = (array) ($i18nFile ?? []);
        $urls = \Preflow\Core\Http\LocaleUrls::fromConfig($i18nFile === null ? null : $i18nConfig, (string) $this->config->get('app.locale', 'en'));
        $this->container->instance(\Preflow\Core\Http\LocaleUrls::class, $urls);
        $cookieName = self::localeCookieName($i18nConfig);

        if (!class_exists(\Preflow\I18n\Translator::class)) {
            return;
        }

        // A translator is always booted: installed packages bring their texts (extra.preflow.translations).
        // Locale detection only with interface strings (lang/) and/or a locale config (a
        // content-only multilingual app may have config/i18n.php but no lang/ yet), so a
        // site with neither keeps its frontend unchanged.
        $langDir = $this->basePath('lang');
        $localized = is_dir($langDir) || $i18nFile !== null;

        $locale = $urls->default;
        // The translation fallback is not part of the URL rule: read from the config itself.
        $fallback = (string) ($i18nConfig['fallback'] ?? $locale);
        $available = $urls->locales;
        $strategy = $urls->prefixed ? 'prefix' : 'none';

        $translator = new \Preflow\I18n\Translator($langDir, $locale, $fallback);
        $this->container->instance(\Preflow\I18n\Translator::class, $translator);
        // Key-shaped labels of types and fields are translated (0.93); without i18n the declared text stays.
        if ($this->container->bound(\Preflow\Data\DataManager::class)) {
            $this->container->get(\Preflow\Data\DataManager::class)->translateLabelsWith(
                static fn (string $key, string $locale): ?string => $translator->has($key, $locale) ? $translator->get($key, [], $locale) : null,
                $translator->getFallbackLocale(),
            );
        }
        if (class_exists(\Preflow\I18n\TranslationRegistry::class)) {
            $registry = $this->translationRegistry();
            $registry->apply($translator);
            $this->container->instance(\Preflow\I18n\TranslationRegistry::class, $registry);
        }

        // t()/tc() and locale helpers
        if ($this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
            $engine = $this->container->get(\Preflow\View\TemplateEngineInterface::class);
            $translationProvider = new \Preflow\I18n\TranslationExtensionProvider($translator, $available, $locale, $urls);
            $this->registerExtensionProvider($engine, $translationProvider);
        }

        // Locale middleware. No cookie by default with the prefix strategy (the URL
        // carries the locale), so sites can truthfully claim "no cookies".
        // With redirect_bare it also sends bare page paths to the visitor's language and answers
        // the language switch (/--locale/{code}), the only place its cookie is written.
        if ($localized && class_exists(\Preflow\I18n\LocaleMiddleware::class)) {
            $nonPublic = $this->container->get(\Preflow\Core\Http\NonPublicPaths::class);
            if ($urls->redirectBare) {
                $nonPublic->add('/--locale');
            }
            $localeMiddleware = new \Preflow\I18n\LocaleMiddleware(
                $available,
                $locale,
                $strategy,
                (bool) ($i18nConfig['cookie'] ?? $strategy !== 'prefix'),
                urls: $urls,
                cookieName: $cookieName,
                pageExists: $this->pageExists($urls),
                nonPublic: $nonPublic,
            );
            $this->container->instance(\Preflow\I18n\LocaleMiddleware::class, $localeMiddleware);
            $this->addMiddleware($localeMiddleware);
            // Right inside it: the context's request (RequestContext, and so `request.path` on
            // controller pages, in page cache rebuilds and in audit renders) names the path without
            // the locale prefix, as file pages, the router and everything after the locale middleware
            // see it. For the rest of the render's frame.
            $this->addMiddleware(new class () implements \Psr\Http\Server\MiddlewareInterface {
                public function process(ServerRequestInterface $request, \Psr\Http\Server\RequestHandlerInterface $handler): ResponseInterface
                {
                    // The locale middleware right before it narrows to this very request: then the
                    // context stays as it is (since 0.82.0, nothing to derive).
                    if (Contexts::depth() > 0) {
                        Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->request?->request === $request ? $c : $c->for($request));
                    }

                    return $handler->handle($request);
                }
            });
        }
    }

    /**
     * The locale cookie's name (config/i18n.php `cookie_name`, default "locale").
     *
     * @param array<array-key, mixed> $i18n
     * @throws \RuntimeException when it is not a non-empty cookie name (RFC 6265 token)
     */
    private static function localeCookieName(array $i18n): string
    {
        $name = $i18n['cookie_name'] ?? 'locale';
        if (!is_string($name) || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
            throw new \RuntimeException('config/i18n.php: cookie_name must be a non-empty cookie name (letters, digits and !#$%&\'*+-.^_`|~).');
        }

        return $name;
    }

    /**
     * Whether a page answers at a bare path, for the locale middleware's redirect (redirect_bare);
     * null when redirect_bare is off. Asks every page lookup there is ({@see pageLookups()}),
     * when a request runs, so lookups and a router that providers set up count.
     *
     * @return (\Closure(string $locale, string $path): bool)|null
     */
    private function pageExists(\Preflow\Core\Http\LocaleUrls $urls): ?\Closure
    {
        if (!$urls->redirectBare) {
            return null;
        }

        $container = $this->container;

        return static function (string $locale, string $path) use ($container): bool {
            foreach (self::pageLookups($container, true) as $lookup) {
                if ($lookup->exists($locale, $path)) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * What can tell whether a page answers at a path (core's Routing\PageExists), built now: the
     * lookups bound under the ids of PageLookups (core's name first, then the names packages added,
     * as preflow/routing adds its Redirect\PageExists), then the router when it implements the
     * contract (preflow/routing's Router does, for routes that are not catch-alls). Called when a
     * request asks, never at boot.
     *
     * With $withAnswers the lookups registered with PageLookups::addAnswer() are asked too (the
     * bare redirect does: an address something answers is worth redirecting); without, pages alone
     * (the clean-path one hop does: a page wins over a redirect entry).
     *
     * @return list<\Preflow\Core\Routing\PageExists>
     */
    private static function pageLookups(Container $container, bool $withAnswers = false): array
    {
        $lookups = [];
        $ids = self::pageLookupIds($container);
        if ($withAnswers && $container->bound(\Preflow\Core\Routing\PageLookups::class)) {
            $ids = [...$ids, ...$container->get(\Preflow\Core\Routing\PageLookups::class)->answers()];
        }
        foreach ([...$ids, RouterInterface::class] as $id) {
            if (!$container->bound($id)) {
                continue;
            }
            $lookup = $container->get($id);
            if ($lookup instanceof \Preflow\Core\Routing\PageExists && !in_array($lookup, $lookups, true)) {
                $lookups[] = $lookup;
            }
        }

        return $lookups;
    }

    /** @return list<string> the ids page lookups are bound under (PageLookups) */
    private static function pageLookupIds(Container $container): array
    {
        return $container->bound(\Preflow\Core\Routing\PageLookups::class)
            ? $container->get(\Preflow\Core\Routing\PageLookups::class)->ids()
            : [\Preflow\Core\Routing\PageExists::class];
    }

    /**
     * redirect_bare redirects only paths a page answers at, so it needs something that can tell;
     * without it, it would do nothing. Checked once providers and their onProvidersBooted hooks ran,
     * without building a lookup: one of the PageLookups ids is bound, or the router (already built)
     * implements PageExists.
     *
     * @throws \RuntimeException when redirect_bare is on and nothing can tell
     */
    private function checkPageLookup(): void
    {
        if (!$this->container->get(\Preflow\Core\Http\LocaleUrls::class)->redirectBare
            || !class_exists(\Preflow\I18n\LocaleMiddleware::class)
            || !$this->container->bound(\Preflow\I18n\LocaleMiddleware::class)) {
            return;
        }
        foreach (self::pageLookupIds($this->container) as $id) {
            if ($this->container->bound($id)) {
                return;
            }
        }
        if ($this->container->bound(RouterInterface::class)
            && $this->container->get(RouterInterface::class) instanceof \Preflow\Core\Routing\PageExists) {
            return;
        }

        throw new \RuntimeException(
            'config/i18n.php: redirect_bare needs to know which paths are pages: use preflow/routing\'s Router, '
            . 'or bind Preflow\\Core\\Routing\\PageExists (a router that implements it counts too).',
        );
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
        // notify's seams on the render context: what sendLater() queues (and what send() would
        // send) follows the current frame's outbox, and a composed mail renders in its
        // recipient's context. notify itself knows nothing of core.
        $queue = new \Preflow\Core\Context\Bridge\NotifyOutbox(
            fn (): \Preflow\Notify\Notifier => $this->container->get(\Preflow\Notify\Notifier::class),
            // Mail queued outside every render: its flush limit goes to the app's logger, once bound.
            function (string $line): void {
                $logger = $this->container->has(\Psr\Log\LoggerInterface::class) ? $this->container->get(\Psr\Log\LoggerInterface::class) : null;
                $logger instanceof \Psr\Log\LoggerInterface ? $logger->error($line) : error_log($line);
            },
        );
        if (Contexts::depth() > 0) {
            $queue->claim(Contexts::frame()->outbox()); // a frame opened before boot (a console command) must not hold another 'notify' channel
        }
        $this->container->instance(\Preflow\Notify\Deferred\DeferredQueue::class, $queue);
        $this->container->instance(\Preflow\Notify\Mail\ComposeScope::class, new \Preflow\Core\Context\Bridge\NotifyComposeScope());
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
                $queue,
            ),
        );
        $this->notifyBooted = true;
        $this->bootMail((array) ($notifyConfig['mail'] ?? []));
    }

    /**
     * The Notifier bound now (a provider may have bound its own) must queue in the container's
     * DeferredQueue, core's NotifyOutbox: with notify's own queue its mail would leave internal
     * renders and previews. Throws in debug mode, logs otherwise.
     *
     * @throws \LogicException in debug mode, when the bound Notifier uses another queue
     */
    private function checkNotifierQueue(DebugLevel $debug): void
    {
        if (!$this->notifyBooted || $this->container->get(\Preflow\Notify\Notifier::class)->queue() === $this->container->get(\Preflow\Notify\Deferred\DeferredQueue::class)) {
            return;
        }
        $message = 'The bound Preflow\Notify\Notifier does not queue in the app\'s DeferredQueue, so its mail would also leave internal renders and previews. '
            . 'Pass it as the fifth argument: new Notifier($factory, $channels, $logger, $defer, $container->get(\Preflow\Notify\Deferred\DeferredQueue::class)).';
        if ($debug->isDebug()) {
            throw new \LogicException($message);
        }
        error_log($message);
    }

    /**
     * Composed mails (preflow/notify's Mailer): the settings from config/notify.php 'mail', the layout
     * and the @notify templates. The layout is chosen on first use: the template layout when the bound
     * template engine can render it (a provider may bind one after this) — a Twig engine, which renders
     * @notify/mail/layout.twig, or any engine when mail.layout names the site's own template —, else
     * the plain PHP one (no engine, or a Blade app without mail.layout). The Mailer asks the container
     * for the Notifier at each send, so a Notifier bound later is the one that sends and flushes. It
     * composes in the recipient's context (NotifyComposeScope).
     *
     * @param array<string, mixed> $mailConfig
     */
    private function bootMail(array $mailConfig): void
    {
        if (!class_exists(\Preflow\Notify\Mail\Mailer::class)) {
            return; // preflow/notify before 0.68
        }

        $config = \Preflow\Notify\Mail\MailConfig::fromArray($mailConfig);
        $this->container->instance(\Preflow\Notify\Mail\MailConfig::class, $config);

        $templates = dirname((string) (new \ReflectionClass(\Preflow\Notify\Mail\Mailer::class))->getFileName(), 3) . '/templates';
        $withTemplates = null; // the engine @notify was added to
        // @notify holds Twig templates: only a Twig engine gets the namespace.
        $addTemplates = static function (\Preflow\View\TemplateEngineInterface $engine) use ($templates, &$withTemplates): bool {
            if ($engine->getTemplateExtension() !== 'twig') {
                return false;
            }
            if ($engine !== $withTemplates) {
                $engine->addNamespace('notify', $templates);
                $withTemplates = $engine;
            }

            return true;
        };
        if ($this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
            $addTemplates($this->container->get(\Preflow\View\TemplateEngineInterface::class));
        }

        /** Resolved on first use; resolving it before the template engine is bound caches BasicMailLayout. */
        $this->container->singleton(\Preflow\Notify\Mail\MailLayout::class, static function (Container $c) use ($addTemplates, $config): \Preflow\Notify\Mail\MailLayout {
            if (!$c->has(\Preflow\View\TemplateEngineInterface::class)) {
                return new \Preflow\Notify\Mail\BasicMailLayout();
            }
            $engine = $c->get(\Preflow\View\TemplateEngineInterface::class);
            if (!$addTemplates($engine) && $config->layout === null) {
                return new \Preflow\Notify\Mail\BasicMailLayout(); // the default layout is Twig
            }

            return new \Preflow\Notify\Mail\TemplateMailLayout($engine);
        });
        $this->container->singleton(\Preflow\Notify\Mail\Mailer::class, static fn (Container $c): \Preflow\Notify\Mail\Mailer => new \Preflow\Notify\Mail\Mailer(
            static fn (): \Preflow\Notify\Notifier => $c->get(\Preflow\Notify\Notifier::class),
            $c->get(\Preflow\Notify\Mail\MailLayout::class),
            $c->get(\Preflow\Notify\Mail\MailConfig::class),
            $c->get(\Preflow\Notify\Mail\ComposeScope::class),
        ));
    }

    private function bootProviders(): void
    {
        $providers = $this->configFile('providers');
        if ($providers !== null) {
            foreach ($providers as $providerClass) {
                if (!class_exists($providerClass)) {
                    $this->reportMissingProvider((string) $providerClass);

                    continue;
                }
                $provider = new $providerClass();
                $this->registerProvider($provider);
            }
        }

        $this->container->bootProviders();
    }

    /**
     * A class listed in config/providers.php that does not exist (a typo, a package not installed):
     * with app.debug on the boot stops naming it; in production it goes to the site's logger
     * (error level; PHP's error log when none is bound yet) and the boot goes on, so one wrong
     * line does not take the site down.
     */
    private function reportMissingProvider(string $class): void
    {
        $message = sprintf('config/providers.php lists "%s", which does not exist (misspelt, or its package is not installed).', $class);
        if (DebugLevel::from((int) $this->config->get('app.debug', 0))->isDebug()) {
            throw new \RuntimeException($message);
        }
        if ($this->container->bound(\Psr\Log\LoggerInterface::class)) {
            $this->container->get(\Psr\Log\LoggerInterface::class)->error($message);

            return;
        }
        error_log($message);
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
            now: static fn (): \DateTimeImmutable => Contexts::clock()->now(),
            warn: $debug->isDebug() ? $this->csrfWarning(...) : null,
            // The visitor's address includes the locale prefix LocaleMiddleware stripped.
            urlPrefix: self::carriedLocalePrefix(...),
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
        $urls = $this->container->get(\Preflow\Core\Http\LocaleUrls::class);

        return [$urls->prefixed ? $urls->locales : [$urls->default], $urls->default];
    }

    /**
     * SEO (config/seo.php): robots.txt, sitemap.xml and security.txt, head
     * tags and JSON-LD on HTML pages, ETag and 304 for pages that are the
     * same for everyone. Prepended to the pipeline just before GEO, so GEO
     * stays outermost and its internal renders pass through SEO too; each
     * render keeps its page meta in its own frame.
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

        // Each render keeps its page meta in its own frame (SeoContext), internal renders too.
        $context = $this->container->get(\Preflow\View\Seo\SeoContext::class);

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
            static fn (): \DateTimeImmutable => Contexts::clock()->now(),
            $this->sessionCookieName(),
            hypermedia: $this->container->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class),
            localeUrls: $this->container->get(\Preflow\Core\Http\LocaleUrls::class),
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
            is_string($appUrl) ? $appUrl : null,
            // Each page GEO describes is an internal Geo render of its own (never stored) that
            // records its geo blocks into the capture GEO hands it; a page that fails is GEO's to
            // report (logged, or the error page in debug mode), as before.
            function (ServerRequestInterface $sub, \Preflow\View\Geo\GeoCapture $capture) use ($geo): ResponseInterface {
                $result = $this->renderWith(
                    $this->internalContext(Purpose::Geo, $sub),
                    fn (): ResponseInterface => $geo->captureInto($capture, fn (): ResponseInterface => $this->handleInFrame($sub)),
                );

                return $result->failure !== null ? throw $result->failure : $result->response;
            },
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
            hypermedia: $this->container->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class),
        );
        $this->container->instance(\Preflow\View\Geo\GeoMiddleware::class, $middleware);
        $this->pipeline->prepend($middleware);
    }

    /**
     * Clean paths (preflow/routing, config/routing.php `'canonical_paths' => true`): a GET or
     * HEAD whose path has a trailing slash or repeated slashes answers 301 to its clean form.
     * Off by default; a value that is not a bool stops the boot.
     */
    private function bootCanonicalPaths(): void
    {
        if (!class_exists(\Preflow\Routing\CanonicalPathMiddleware::class)
            || !\Preflow\Routing\CanonicalPathMiddleware::enabled($this->configFile('routing'))) {
            return;
        }

        // A clean path with a redirect entry is answered in one hop. The store may be bound after
        // boot (a provider of an admin package), so the resolver and the hit are looked up per request.
        $container = $this->container;
        $store = static function () use ($container): ?\Preflow\Routing\Redirect\RedirectStore {
            return class_exists(\Preflow\Routing\Redirect\RedirectResolver::class)
                && $container->bound(\Preflow\Routing\Redirect\RedirectStore::class)
                    ? $container->get(\Preflow\Routing\Redirect\RedirectStore::class)
                    : null;
        };

        $this->pipeline->prepend(new \Preflow\Routing\CanonicalPathMiddleware(
            $container->get(\Preflow\Core\Http\NonPublicPaths::class),
            static function () use ($container, $store): ?\Preflow\Routing\Redirect\RedirectResolver {
                if ($store() === null) {
                    return null;
                }

                return new \Preflow\Routing\Redirect\RedirectResolver(
                    $container->get(\Preflow\Routing\Redirect\RedirectStore::class),
                    $container->bound(\Preflow\Routing\Redirect\RedirectTargets::class)
                        ? $container->get(\Preflow\Routing\Redirect\RedirectTargets::class)
                        : null,
                );
            },
            $container->get(\Preflow\Core\Http\LocaleUrls::class),
            // The hit is a 'log' write of the write policy, counted at the render's "now".
            static function (\Preflow\Routing\Redirect\Redirect $entry) use ($store): void {
                $redirects = $store();
                if ($redirects === null || !\Preflow\Core\Context\Writes::allowed('redirects', 'redirect-hits', $entry->id, 'log')) {
                    return;
                }
                $redirects->hit($entry, Contexts::current()->clock->now());
            },
            // A page at the clean path wins over an entry there, as it does for a 404.
            static function (string $locale, string $path) use ($container): bool {
                foreach (self::pageLookups($container) as $lookup) {
                    if ($lookup->exists($locale, $path)) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * The SEO audit's PageRenderer: renders each page through the app with render(), as an
     * internal Audit render in the page's locale (so the whole pipeline, from the console too),
     * and the Audits service around it (since 0.74). Both built on first use only, so web
     * requests don't pay for them. A render from inside a request (a step run from the admin)
     * leaves that request as it was, its debug data included: internal renders record none.
     */
    private function bootAudit(): void
    {
        if (!$this->container->bound(\Preflow\View\Audit\AuditRegistry::class)) {
            return;
        }

        $this->container->singleton(\Preflow\View\Audit\PageRenderer::class, function (): \Preflow\View\Audit\PageRenderer {
            [$locales, $defaultLocale] = $this->siteLocales();
            $appUrl = $this->config->get('app.url');

            return new \Preflow\View\Audit\PageRenderer(
                fn (ServerRequestInterface $request, string $locale): RenderResult => $this->render(
                    $this->internalContext(Purpose::Audit, $request, $locale),
                    $request,
                ),
                is_string($appUrl) ? $appUrl : '',
                $locales,
                $defaultLocale,
            );
        });

        // The audit as a service (a step, the quick checks, the saved report), for the command and the admin.
        $this->container->singleton(\Preflow\View\Audit\Audits::class, fn (): \Preflow\View\Audit\Audits => new \Preflow\View\Audit\Audits(
            $this->container->get(\Preflow\View\Audit\AuditRegistry::class),
            $this->container->get(\Preflow\View\Audit\PageRenderer::class),
            $this->container->get(\Preflow\View\Seo\PublicPages::class),
            \Preflow\View\Audit\AuditReport::file($this->basePath()),
        ));
    }

    /**
     * The context of an internal render of $request (the audit's, GEO's): RenderContext::internal()
     * of its path, in $locale (else the locale of the path's URL prefix, else the site's default),
     * carrying $request (its clean headers, its session-less attribute).
     */
    private function internalContext(Purpose $purpose, ServerRequestInterface $request, ?string $locale = null): RenderContext
    {
        $site = $this->site();
        $path = $request->getUri()->getPath();
        $path = $path === '' ? '/' : $path;
        $locale ??= $site->urls?->localeOf($path) ?? $site->defaultLocale;

        return RenderContext::internal($purpose, $site, $this->clock(), $path)
            ->in(in_array($locale, $site->locales, true) ? $locale : $site->defaultLocale)
            ->for($request);
    }

    /**
     * $handle with the site's SEO defaults of the current render (SeoContext::siteDefaults())
     * given to the frame it runs in: verify's second render runs the rest of the pipeline only,
     * which the SEO middleware (outside the page cache) does not see again, so a page that
     * prints the site's default description renders the same twice. Its page meta starts empty.
     *
     * @param \Closure(): ResponseInterface $handle
     * @return \Closure(): ResponseInterface
     */
    private function withSiteDefaults(\Closure $handle): \Closure
    {
        if (!class_exists(\Preflow\View\Seo\SeoContext::class) || !$this->container->bound(\Preflow\View\Seo\SeoContext::class)) {
            return $handle;
        }
        $seo = $this->container->get(\Preflow\View\Seo\SeoContext::class);
        $defaults = $seo->siteDefaults();

        return static function () use ($seo, $defaults, $handle): ResponseInterface {
            $seo->startWith($defaults);

            return $handle();
        };
    }

    /**
     * The locale prefix the current render's URL carried ('/de'; '/en' for the default locale
     * written into the URL), '' for none: what LocaleMiddleware set in the request part. The page
     * cache's key, component URLs and the redirect check use it, so they see the address as the
     * visitor wrote it. Outside every render there is no URL, so no prefix.
     */
    private static function carriedLocalePrefix(): string
    {
        return Contexts::depth() > 0 ? Contexts::current()->request?->localePrefix ?? '' : '';
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
        // The container's session follows the render: a role view or a preview token's render gets an
        // empty one of its own, never the editor's. The middleware keeps the real one.
        $this->container->instance(\Preflow\Core\Http\Session\SessionInterface::class, new \Preflow\Core\Http\Session\RenderSession($session));

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
            $this->container->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class),
            $config,
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

    /**
     * The app's own error pages (preflow/view's ErrorPageRenderer) when app/pages/_errors/ exists and
     * an engine is bound, falling back to $builtIn; else $builtIn itself.
     */
    private function appErrorPages(ProdErrorRenderer $builtIn): \Preflow\Core\Error\ErrorRendererInterface
    {
        if (!class_exists(\Preflow\View\ErrorPageRenderer::class)
            || !is_dir($this->basePath('app/pages/' . \Preflow\View\ErrorPageRenderer::DIR))
            || !$this->container->has(\Preflow\View\TemplateEngineInterface::class)
            || !$this->container->bound(\Preflow\View\AssetCollector::class)) {
            return $builtIn;
        }

        return new \Preflow\View\ErrorPageRenderer(
            $this->container->get(\Preflow\View\TemplateEngineInterface::class),
            $this->container->get(\Preflow\View\AssetCollector::class),
            $builtIn,
            $this->container->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class),
            $this->csrfWarning(...),
        );
    }

    /** Debug-mode CSRF and redirect warnings, and failed error pages: to the bound logger (a provider may bind it after boot), else the PHP log. */
    private function csrfWarning(string $message): void
    {
        if ($this->container->has(\Psr\Log\LoggerInterface::class)) {
            $this->container->get(\Psr\Log\LoggerInterface::class)->warning($message);
            return;
        }
        error_log($message);
    }

    /**
     * A guard of the app's own class (config/auth.php guards.<name>.class), checked now and built on
     * first use through the container with `provider` (its built user provider) and `config` (its
     * whole entry), so it may take services the providers bind and #[Config] values.
     */
    private function lazyGuard(string $name, string $class, \Preflow\Auth\UserProviderInterface $provider, array $config): \Preflow\Auth\LazyGuard
    {
        if (!class_exists($class)) {
            throw new \RuntimeException("Guard \"{$name}\": class {$class} not found.");
        }
        if (!is_subclass_of($class, \Preflow\Auth\GuardInterface::class)) {
            throw new \RuntimeException("Guard \"{$name}\" ({$class}) does not implement " . \Preflow\Auth\GuardInterface::class . '.');
        }
        $container = $this->container;

        return new \Preflow\Auth\LazyGuard($name, $class, static fn (): object => $container->make($class, ['provider' => $provider, 'config' => $config]));
    }

    private function runProvidersBootedHooks(): void
    {
        $hooks = $this->providersBootedHooks ?? [];
        $this->providersBootedHooks = null;
        foreach ($hooks as $hook) {
            $hook();
        }
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

        // Password hasher: a container binding (config/auth.php password_hasher, default
        // NativePasswordHasher, built by the container), which an app's provider may replace. Session
        // guards are made now, before the providers, so they ask the container on each use.
        $hasherClass = (string) ($authConfig['password_hasher'] ?? \Preflow\Auth\NativePasswordHasher::class);
        if (!class_exists($hasherClass) || !is_subclass_of($hasherClass, \Preflow\Auth\PasswordHasherInterface::class)) {
            throw new \RuntimeException("config/auth.php password_hasher: {$hasherClass} is not the class of a " . \Preflow\Auth\PasswordHasherInterface::class . '.');
        }
        if (!$this->container->bound(\Preflow\Auth\PasswordHasherInterface::class)) {
            $this->container->singleton(
                \Preflow\Auth\PasswordHasherInterface::class,
                static fn (Container $c): object => $c->make($hasherClass),
            );
        }
        $container = $this->container;
        $hasher = new \Preflow\Auth\LazyPasswordHasher(static fn (): \Preflow\Auth\PasswordHasherInterface => $container->get(\Preflow\Auth\PasswordHasherInterface::class));

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
        // The session guards' cookies go to the current render frame's queue: ResolveUserMiddleware (with a session) and the Kernel apply it.
        $cookieQueue = $this->container->get(\Preflow\Core\Http\CookieQueue::class);
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
                // Any other class: built by the container on first use, after the service providers ran, with
                // its user provider and its whole config entry (as user providers get theirs).
                default => $this->lazyGuard($name, $guardClass, $provider, (array) $guardConfig),
            };
            if ($guard !== null && !$guard instanceof \Preflow\Auth\GuardInterface && !$guard instanceof \Preflow\Auth\LazyGuard) {
                throw new \RuntimeException("Guard \"{$name}\" ({$guardClass}) does not implement " . \Preflow\Auth\GuardInterface::class . '.');
            }
            if ($guard !== null) {
                $guards[$name] = $guard;
            }
        }

        $loginPaths = [];
        $guardProviders = [];
        foreach ($authConfig['guards'] ?? [] as $name => $guardConfig) {
            if (is_string($guardConfig['login'] ?? null)) {
                $loginPaths[(string) $name] = $guardConfig['login'];
            }
            if (isset($guards[(string) $name], $providers[$guardConfig['provider'] ?? ''])) {
                $guardProviders[(string) $name] = $providers[$guardConfig['provider']];
            }
        }
        $authManager = new \Preflow\Auth\AuthManager($guards, $defaultGuard, $loginPaths, $guardProviders);
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
        // Preview tokens are re-checked through Access at every use (not without config/auth.php,
        // where can() allows everything: the default gate admits no token there).
        $this->container->instance(\Preflow\Core\Context\Preview\PreviewGate::class, new \Preflow\Auth\Access\AccessPreviewGate($access));

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
            // Right after it: the follow-ups of a staff member's preview that carry its signed token
            // render as the preview (its role or visitor, read mode, locale), in a frame of their own.
            $this->addMiddleware($this->previewTokenMiddleware($authManager));
        }

        // Register middleware instances in container for route-level use
        $this->container->instance(
            \Preflow\Auth\Http\AuthMiddleware::class,
            new \Preflow\Auth\Http\AuthMiddleware(
                $authManager,
                null,
                $this->container->has(\Preflow\Core\Http\Api\ApiConfig::class) ? $this->container->get(\Preflow\Core\Http\Api\ApiConfig::class) : null,
                $this->container->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class),
                $access,
            ),
        );
        $this->container->instance(
            \Preflow\Auth\Http\TokenAuthMiddleware::class,
            new \Preflow\Auth\Http\TokenAuthMiddleware($authManager, isset($authConfig['api_guard']) ? (string) $authConfig['api_guard'] : null),
        );
        $this->container->instance(
            \Preflow\Auth\Http\GuestMiddleware::class,
            new \Preflow\Auth\Http\GuestMiddleware($authManager, access: $access),
        );

        // Template functions: flash() and csrf_token() read the current render's session (the request's
        // own; an internal render has none), not the session boot made.
        $session = $this->container->has(\Preflow\Core\Http\Session\SessionInterface::class)
            ? static fn (): ?\Preflow\Core\Http\Session\SessionInterface => \Preflow\Core\Http\Session\SessionMiddleware::ofCurrentRender()
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

    /**
     * The middleware that admits a preview's follow-up requests (PreviewTokenMiddleware): the
     * granted render's request carries the frame's own user (a role view's SyntheticUser, or
     * none), never the editor's.
     */
    private function previewTokenMiddleware(\Preflow\Auth\AuthManager $authManager): \Preflow\Core\Context\Preview\PreviewTokenMiddleware
    {
        $container = $this->container;

        return new \Preflow\Core\Context\Preview\PreviewTokenMiddleware(
            tokens: $container->get(\Preflow\Core\Context\Preview\PreviewToken::class),
            gate: static fn (): \Preflow\Core\Context\Preview\PreviewGate => $container->get(\Preflow\Core\Context\Preview\PreviewGate::class),
            nonPublic: $container->get(\Preflow\Core\Http\NonPublicPaths::class),
            render: $this->renderRestOfRequest(...),
            errors: static fn (\Throwable $e, ServerRequestInterface $request): ResponseInterface => $container->get(ErrorHandler::class)->handleException($e, $request),
            viewerAttributes: static function (ServerRequestInterface $request) use ($authManager): ServerRequestInterface {
                $request = $request->withoutAttribute(\Preflow\Auth\Authenticatable::class)->withoutAttribute(\Preflow\Auth\AuthManager::GUARD_ATTRIBUTE);
                $user = $authManager->user();

                return $user === null ? $request : $request
                    ->withAttribute(\Preflow\Auth\Authenticatable::class, $user)
                    ->withAttribute(\Preflow\Auth\AuthManager::GUARD_ATTRIBUTE, $authManager->guardName());
            },
            log: $this->pageCacheLogger(),
            clients: $container->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class),
        );
    }

    /**
     * Runs $handle, the rest of the current request, in a frame of its own under $ctx
     * (Contexts::run()): a render as someone else (a preview token's role or visitor) never
     * narrows the request's frame. Its answer is the request's own, so it prints the request
     * frame's CSP nonce, which the response's policy holds.
     *
     * @param \Closure(): ResponseInterface $handle
     */
    private function renderRestOfRequest(RenderContext $ctx, \Closure $handle): ResponseInterface
    {
        $outer = Contexts::frame();

        return Contexts::run($ctx, function () use ($outer, $handle): ResponseInterface {
            if (class_exists(\Preflow\View\NonceGenerator::class) && $this->container->bound(\Preflow\View\NonceGenerator::class)) {
                $this->container->get(\Preflow\View\NonceGenerator::class)->share($outer);
            }

            return $handle();
        });
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
                    $c->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class),
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
                    'request' => \Preflow\View\PageResponder::requestVariable($request, $container->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class)),
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
