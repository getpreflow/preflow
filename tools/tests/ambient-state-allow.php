<?php

declare(strict_types=1);

/**
 * The permanent allowlist of architecture.no-ambient-state (see ArchitectureGuardsTest).
 *
 * File (relative to packages/, a glob is allowed) => rule => either a reason (the whole file may
 * hit that rule) or [trimmed line text => reason] (only those lines may). Every entry must still
 * match a hit; remove an entry when its code goes. The shrinking list of hits still to move onto
 * the render context is ambient-state-baseline.php, not this file.
 *
 * @return array<string, array<string, string|array<string, string>>>
 */
return [
    // superglobal: the edges where PHP's request becomes a value, and the adapters for code
    // outside the packages that still reads superglobals.
    'core/src/Application.php' => [
        'superglobal' => [
            '$request = $creator->fromGlobals();' => 'the HTTP edge: the one place that turns the superglobals into the request',
        ],
        'request-binding' => [
            '$this->container->bind(RequestContext::class, static fn (): ?RequestContext => Contexts::peek()?->request);' => 'the one sanctioned RequestContext binding: a factory that reads the current frame\'s request, so no render finds another\'s',
            '$this->container->instance(\\Preflow\\Core\\Http\\CookieQueue::class, \\Preflow\\Core\\Http\\CookieQueue::ofCurrentFrame());' => 'the one sanctioned CookieQueue binding: bound once at boot, it works on the current frame\'s own queue (a frame slot)',
        ],
        'peek' => [
            '$this->container->bind(RequestContext::class, static fn (): ?RequestContext => Contexts::peek()?->request);' => 'outside every render there is no request: RequestContext is null, as before the first request',
        ],
    ],
    'core/src/Http/InternalRender.php' => [
        'peek' => [
            'return Contexts::peek()?->purpose->leavesNoTrace() === true;' => 'outside every render nothing is internal (the documented default)',
        ],
        'setter' => [
            "Deprecations::once('Preflow\\Core\\Http\\InternalRender::mark() is deprecated since 0.81 and is removed in 0.83; use Application::render() with RenderContext::internal().');" => 'the deprecation message names mark(); not a call',
        ],
    ],
    'core/src/Http/Session/SessionMiddleware.php' => [
        'peek' => [
            '$session = Contexts::peek()?->request?->request?->getAttribute(SessionInterface::class);' => 'outside every render there is no request and so no session: flash(), csrf_token() and old() read none (the documented default)',
        ],
    ],
    'core/src/Context/Writes.php' => [
        'peek' => [
            'return Contexts::peek()?->writes ?? WritePolicy::Allow;' => 'outside every render writes run (ruling R1: DataManager writes allow without a frame)',
        ],
    ],
    'core/src/Http/Session/NativeSession.php' => [
        'superglobal' => 'PHP\'s native session lives in $_SESSION; this class is the one adapter over it',
    ],
    'core/src/PageCache/AnonymousGlobals.php' => [
        'superglobal' => 'the superglobal adapter: internal renders swap the superglobals for site code that still reads them',
    ],
    'devtools/src/router.php' => [
        'superglobal' => 'the router script of PHP\'s built-in development server, which runs before any app',
    ],
    'testing/src/WebApplication.php' => [
        'superglobal' => 'test process setup: clears a $_SESSION left over by earlier tests before an app boots',
    ],
    'testing/src/Contract/RenderIsolationContract.php' => [
        'superglobal' => 'the isolation contract plants a marker in the superglobals on purpose (and restores them after each test), to prove no render reads them',
        'setter' => 'the isolation contract calls the deprecated ambient setters on purpose, to prove no render reads what they set; drop each call when its shim is removed',
        'request-binding' => [
            '$container->instance(RequestContext::class, new RequestContext(\'/\', \'GET\', $this->poisonedRequest()));' => 'the isolation contract binds a poisoned visitor request on purpose, as a request binds it, to prove no render reads it',
        ],
    ],

    // clock: durations and deadlines, which measure elapsed time and never read "now" for behaviour.
    'core/src/Debug/DebugCollector.php' => [
        'peek' => [
            'return Contexts::peek()?->purpose->isInternal() !== true;' => 'outside every render the collector records (a script, a test), as before 0.81',
        ],
        'clock' => [
            '$this->startTime = hrtime(true);' => 'measures elapsed time or a deadline; a frozen context clock must not apply',
            'return (hrtime(true) - $this->startTime) / 1_000_000;' => 'measures elapsed time or a deadline; a frozen context clock must not apply',
        ],
    ],
    'devtools/src/Command/PagesWorkCommand.php' => [
        'clock' => [
            '$started = microtime(true);' => 'measures elapsed time or a deadline; a frozen context clock must not apply',
            'printf("Rebuilt %d page(s) in %.1f s; %d queued.\\n", $rebuilt, microtime(true) - $started, $refresher->queued());' => 'measures elapsed time or a deadline; a frozen context clock must not apply',
        ],
    ],
    'media/src/Preview/VideoPreviewRenderer.php' => [
        'clock' => [
            '$deadline = microtime(true) + $this->timeLimit;' => 'measures elapsed time or a deadline; a frozen context clock must not apply',
            'if (microtime(true) >= $deadline) {' => 'measures elapsed time or a deadline; a frozen context clock must not apply',
        ],
    ],

    // clock: the clock implementation.
    'core/src/Context/SystemClock.php' => [
        'clock' => 'the system clock itself; everything else reads the context\'s clock',
    ],

    // setter: a call that is named like an ambient setter but is none.
    'folio/src/Http/LocaleController.php' => [
        'setter' => 'UserPreferences::setLocale() stores a person\'s admin language; it changes no render state',
    ],

    // peek: only code with a documented default outside a render (ruling R1).
    'core/src/Context/NoRenderContext.php' => [
        'peek' => [
            ". 'Open one with Contexts::run(), or use Contexts::peek() where a default without a render is documented.'," => 'the exception message names peek(); not a call',
        ],
    ],

    // context-property and frame-context-write: the context switch itself.
    'core/src/Context/RenderFrame.php' => [
        'context-property' => 'a frame holds the context its render runs under; only Contexts sets it',
    ],
    'core/src/Context/Contexts.php' => [
        'static-state' => 'the process-wide frame stack, the enter hooks and the debug flag: the one holder of the current context',
        'frame-context-write' => 'narrow() and within() are the only ways to change a frame\'s context',
    ],

    // static-state: process caches and once-per-process flags. Each holds the same value for every
    // render (built from code, files or the installed packages), never request or render state.
    'core/src/Context/Deprecations.php' => [
        'static-state' => [
            'private static array $reported = [];' => 'call sites that already reported a deprecation: once per process by design',
        ],
    ],
    'core/src/Config/ParsedJsonFiles.php' => [
        'static-state' => [
            'private static array $files = [];' => 'parsed JSON config files by path and modification time',
            'private static int $decodes = 0;' => 'how many files were decoded, a counter tests read',
        ],
    ],
    'core/src/Composer/InstalledPackages.php' => [
        'static-state' => [
            'private static array $memo = [];' => 'the installed packages, read once per process',
        ],
    ],
    'core/src/Version.php' => [
        'static-state' => [
            'private static ?array $pathInstalls = null;' => 'the packages installed.json marks as path installs, read once',
        ],
    ],
    'core/src/PageCache/TagIndex.php' => [
        'static-state' => [
            'private static array $logged = [];' => 'index files whose failure was already logged in this process',
        ],
    ],
    'core/src/PageCache/PageCacheConfig.php' => [
        'static-state' => [
            'static $warned = false;' => 'a config warning written once per process',
        ],
    ],
    'auth/src/SessionGuard.php' => [
        'static-state' => [
            'private static array $dummies = [];' => 'dummy password hashes per hasher option set, for timing-safe failed sign-ins',
        ],
    ],
    'ai/src/Ai.php' => [
        'static-state' => [
            'private static array $reported = [];' => 'configuration warnings already reported in this process',
        ],
    ],
    'components/src/ComponentRenderer.php' => [
        'clock' => [
            '$start = hrtime(true);' => 'measures elapsed time or a deadline; a frozen context clock must not apply',
            '$durationMs = (hrtime(true) - $start) / 1_000_000;' => 'measures elapsed time or a deadline; a frozen context clock must not apply',
        ],
        'static-state' => [
            'private static array $liveClasses = [];' => 'whether a component class is live: a per-class fact',
        ],
    ],
    'data/src/Model.php' => [
        'static-state' => [
            'private static array $boolProperties = [];' => 'a model class\'s boolean properties, from reflection',
        ],
    ],
    'data/src/ModelMetadata.php' => [
        'static-state' => [
            'private static array $cache = [];' => 'model metadata per class, from attributes',
        ],
    ],
    'data/src/DataManager.php' => [
        'static-state' => [
            'private static array $noticedNotVersioned = [];' => 'driver classes already told about, once per process',
        ],
    ],
    'data/src/Driver/PdoDriver.php' => [
        'clock' => [
            '$start = hrtime(true);' => 'measures elapsed time or a deadline; a frozen context clock must not apply',
            '$durationMs = (hrtime(true) - $start) / 1_000_000;' => 'measures elapsed time or a deadline; a frozen context clock must not apply',
            '$this->collector?->logQuery($sql, $bindings, (hrtime(true) - $start) / 1_000_000);' => 'measures elapsed time or a deadline; a frozen context clock must not apply',
        ],
        'static-state' => [
            'private static int $savepoints = 0;' => 'a counter that names savepoints uniquely in the process',
        ],
    ],
    'data/src/Migration/Grammar/Grammars.php' => [
        'static-state' => [
            'private static array $registered = [];' => 'schema grammars registered by dialect name',
        ],
    ],
    'data/src/Migration/Grammar/MysqlGrammar.php' => [
        'static-state' => [
            'private static ?\WeakMap $probed = null;' => 'each connection\'s probed collation (a WeakMap keyed by the connection)',
        ],
    ],
    'data/src/Migration/Grammar/SqliteGrammar.php' => [
        'static-state' => [
            'private static ?\WeakMap $prepared = null;' => 'the connections found to have the collation (a WeakMap keyed by the connection)',
        ],
    ],
    'data/src/Text/TextMatch.php' => [
        'static-state' => [
            'private static ?array $table = null;' => 'the folding table, built once',
            'private static ?array $matchTable = null;' => 'the match folding table, built once',
            'private static ?array $asciiTargets = null;' => 'the ASCII targets of the folding table, built once',
            'private static array $patterns = [];' => 'compiled regular expressions by escape and pattern',
            'private static ?array $equality = null;' => 'the equality table, built once',
            'private static ?\Collator $collator = null;' => 'the collator, built once',
            'private static array $keys = [];' => 'folded keys of short texts (bounded by CACHED_KEYS)',
            'static $tables = null;' => 'a digest of the fold and equality tables, computed once',
        ],
    ],
    'folio/src/FolioServiceProvider.php' => [
        'static-state' => [
            'private static ?\WeakMap $editableApplied = null;' => 'translators already given the editable groups (a WeakMap keyed by the translator)',
        ],
    ],
    'folio/src/FolioText.php' => [
        'static-state' => [
            'private static ?Translator $english = null;' => 'the English fallback catalogue, loaded once',
        ],
    ],
    'folio/src/Field/LazyHtmlSanitizer.php' => [
        'static-state' => [
            'public static int $built = 0;' => 'a test hook: how many real sanitizers were built',
        ],
    ],
    'folio/src/Permissions/KnownPermissions.php' => [
        'static-state' => [
            'private static ?TypeDefinition $definition = null;' => 'the type definition of its record store, built from code once',
        ],
    ],
    'folio/src/Redirects/AddressTable.php' => [
        'static-state' => [
            'private static ?TypeDefinition $definition = null;' => 'the type definition of its record store, built from code once',
        ],
    ],
    'folio/src/Redirects/NotFoundEntries.php' => [
        'static-state' => [
            'private static ?TypeDefinition $definition = null;' => 'the type definition of its record store, built from code once',
        ],
    ],
    'folio/src/Redirects/RedirectEntries.php' => [
        'static-state' => [
            'private static ?TypeDefinition $definition = null;' => 'the type definition of its record store, built from code once',
        ],
    ],
    'folio/src/View/InlineIcon.php' => [
        'static-state' => [
            'private static ?array $symbols = null;' => 'the icon sprite\'s symbols, read once',
        ],
    ],
    'htmx/src/HtmxAssets.php' => [
        'static-state' => [
            'private static array $scripts = [];' => 'the htmx build, read once per process',
        ],
    ],
    'media/src/Preview/PdfPreviewRenderer.php' => [
        'clock' => [
            '$deadline = microtime(true) + $this->timeLimit;' => 'measures elapsed time or a deadline; a frozen context clock must not apply',
            '$out = self::run([$gs, \'--version\'], microtime(true) + self::VERSION_LIMIT, capture: true);' => 'measures elapsed time or a deadline; a frozen context clock must not apply',
            'if (microtime(true) >= $deadline) {' => 'measures elapsed time or a deadline; a frozen context clock must not apply',
        ],
        'static-state' => [
            'private static array $versions = [];' => 'the installed Ghostscript version per binary',
        ],
    ],
    'validation/src/Message.php' => [
        'static-state' => [
            'static $texts = null;' => 'the English message texts, loaded once',
        ],
    ],
    'view/src/AssetCollector.php' => [
        'static-state' => [
            'private static array $scopedCache = [];' => 'scoped CSS by scope and text, compiled once per process',
            'private static ?string $minifierVersion = null;' => 'the installed minifier\'s version, read once',
        ],
    ],
    'view/src/Qr/QrCode.php' => [
        'static-state' => [
            'private static ?string $libraryClass = null;' => 'which QR library is installed, checked once',
        ],
    ],
    'testing/src/Fast/SetupSnapshots.php' => [
        'static-state' => [
            'private static array $memory = [];' => 'set-up snapshots of the test process',
        ],
    ],
    'testing/src/MysqlTestDatabase.php' => [
        'static-state' => [
            'private static ?\PDO $pdo = null;' => 'the test process\'s MySQL connection',
        ],
    ],
    'starter/app/Support/Markdown.php' => [
        'static-state' => [
            'private static ?CommonMarkConverter $converter = null;' => 'the Markdown converter, built once',
        ],
    ],
    'starter-folio/app/Support/Markdown.php' => [
        'static-state' => [
            'private static ?CommonMarkConverter $converter = null;' => 'the Markdown converter, built once',
        ],
    ],
];
