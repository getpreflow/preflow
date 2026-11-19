<?php

declare(strict_types=1);

namespace Preflow\Testing\Contract;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Core\Application;
use Preflow\Core\Context\CapturedItem;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Context\Viewer;
use Preflow\Core\Http\CookieQueue;
use Preflow\Core\Http\RequestContext;
use Preflow\Core\Identity;
use Preflow\I18n\TranslationOverrides;
use Preflow\I18n\Translator;
use Preflow\Testing\ContextFactory;
use Preflow\View\AssetCollector;
use Preflow\View\JsPosition;
use Preflow\View\Seo\PageMeta;
use Preflow\View\Seo\SeoContext;

/**
 * An internal render leaves no trace, as tests. A site, a package or an adapter that renders
 * pages for itself (an SEO audit, a Markdown view, a rebuild, a preview, a mail, a PDF) extends
 * it, implements the two hooks, and runs it with PHPUnit. Folio is not needed.
 *
 *     final class SiteRenderIsolationTest extends RenderIsolationContract
 *     {
 *         protected function app(): Application
 *         {
 *             $app = Application::create(dirname(__DIR__));
 *             $app->boot();
 *
 *             return $app;
 *         }
 *
 *         protected function paths(): array
 *         {
 *             return [
 *                 'audit' => static fn (Application $app, RenderContext $ctx): RenderResult => $app->render(
 *                     RenderContext::internal(Purpose::Audit, $ctx->site, $ctx->clock, '/')->in($ctx->locale),
 *                     new ServerRequest('GET', '/'),
 *                 ),
 *             ];
 *         }
 *     }
 *
 * Hooks:
 * - app(): a booted application. Called once per path in each test, so no outcome depends on
 *   the paths before it; return a new one each time (the tests change its services).
 * - paths(): path name => a closure that renders under the context it is given and returns the
 *   RenderResult (the response, what its frame captured, the writes it skipped). Names are from
 *   PATHS: audit, geo, rebuild, verify, preview, mail, pdf; cover the ones the app has.
 * - pending() (optional): path => [test => reason], the tests a path does not pass yet; the test
 *   names are TESTS' keys (poison, frame, interleave, frozen) or '*' for all four. A pending test
 *   still runs: when it fails, the test is marked incomplete (never skipped) with the reason and
 *   the whole failure; when it passes, the test fails with "<path>/<test> passes: remove it from
 *   pending()". A test that is not pending must pass.
 * - marker() (optional): the text planted in the ambient state; no render may print it.
 * - context() (optional): the context every render starts from (ContextFactory::forTest()); give
 *   your site's origin and locales here.
 * - clockFormat() (optional): the date format the app's page prints its render time in; when
 *   given, the frozen-clock test checks that the render printed its own frozen time.
 * - poisonApp(), ambientState() and restoreApp() (optional): plant the marker in the app's own
 *   ambient sources (a package's static locale, say), read them for the outer-frame snapshot,
 *   and put them back. restoreApp() runs in tearDown() for every app the test used, also after a
 *   failure, and ambientState() must then be back to its value before the poison. A non-empty
 *   ambientState() must change when poisonApp() runs (a constant one checks nothing). State that
 *   outlives the app (statics) must be restored there, or the runner must run its tests in
 *   separate processes (#[RunTestsInSeparateProcesses]).
 *
 * Each render runs inside an outer frame of the test's own, as an internal render runs inside the
 * request that asks for it. The tests:
 * - poison: the outer frame runs under a poisoned visitor context (another locale, a viewer whose
 *   id is the marker, working copies, the admin surface, another frozen time) whose request
 *   carries the cookie `m`, the header X-Marker and REMOTE_ADDR set to the marker; the container's
 *   RequestContext gives the same request (bound as an instance where the app does not bind it to
 *   the current frame). The superglobals get the same markers and
 *   $_SESSION['m']. Where the app has them, the translator's deprecated setLocale('xx') and
 *   overrides that answer every key with the marker, AuthManager::setUser() with the marker
 *   user and LiveContent::showWorkingCopies() are called; a CSS rule, a script and a page title
 *   holding the marker go into the asset registry and the SEO page meta; one cookie
 *   (CookieQueue) and one mail (the frame's outbox, channel 'mail') are queued; then poisonApp().
 *   The render's body, headers and captured items must not contain the marker, and its status
 *   and body must equal the same render's under a clean outer frame;
 * - frame: the same poison, then a snapshot of the outer frame before and after the render: the
 *   current context (serialize(), which leaves the PSR request out), the frame's queued items,
 *   the CookieQueue, the bound RequestContext, the asset registry, the SEO page meta, the
 *   translator's locale and overrides switch, the signed-in user, LiveContent's mode, the
 *   superglobals, the native session's status and id, and ambientState(). The render must also
 *   leave the same frame current at the same depth;
 * - interleave: A, B, A in one outer frame that runs under B; B has another locale, a signed-in
 *   viewer and working copies. Both A renders give the same status, headers (without Date), body
 *   and captured items, and B's differ from A's (so the page must show something of the locale or
 *   the viewer);
 * - frozen: two renders of the same context frozen with at(), inside an outer frame at another
 *   time, give the same output; with clockFormat(), the body holds the frozen time. A page that
 *   prints a nonce needs the nonce made deterministic for this test.
 *
 * Superglobals are restored after each test.
 *
 * @since 0.81.0
 */
abstract class RenderIsolationContract extends TestCase
{
    /** The internal render paths a runner may cover. */
    public const array PATHS = ['audit', 'geo', 'rebuild', 'verify', 'preview', 'mail', 'pdf'];

    /** The tests, by the name pending() uses. */
    public const array TESTS = [
        'poison' => 'test_poisoned_ambient_state_never_reaches_the_render',
        'frame' => 'test_outer_frame_is_byte_equal_after_each_render',
        'interleave' => 'test_interleaved_renders_are_independent',
        'frozen' => 'test_frozen_clock_renders_identically',
    ];

    /** A booted application; called once per test. */
    abstract protected function app(): Application;

    /**
     * @return array<string, \Closure(Application, RenderContext): RenderResult> path name (from PATHS) => renderer
     */
    abstract protected function paths(): array;

    /** @return array<string, array<string, string>> path => [test name from TESTS or '*' => why it does not pass yet] */
    protected function pending(): array
    {
        return [];
    }

    /** The text planted in the ambient state. */
    protected function marker(): string
    {
        return 'zz-ambient-marker';
    }

    /** The context every render starts from. */
    protected function context(): RenderContext
    {
        return ContextFactory::forTest();
    }

    /** The date format the page prints its render time in; null: the page prints none. */
    protected function clockFormat(): ?string
    {
        return null;
    }

    /** Plants the marker in the app's own ambient sources; runs at the end of the poison. */
    protected function poisonApp(Application $app): void {}

    /** @return array<string, mixed> the app's own ambient state, compared before and after each render */
    protected function ambientState(Application $app): array
    {
        return [];
    }

    /**
     * Undoes what poisonApp() set, statics included. Runs in tearDown() for every app the test
     * used, also when the test failed; afterwards ambientState() must be back to its value from
     * before the poison.
     */
    protected function restoreApp(Application $app): void {}

    /** @var list<Application> the apps this test used, for restoreApp() */
    private array $apps = [];

    /** @var array<int, array<string, mixed>> ambientState() of each poisoned app before its poison, by object id */
    private array $ambientBefore = [];

    /** @var array{cookie: array<mixed>, server: array<mixed>, session: array<mixed>|null} */
    private array $globals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->globals = ['cookie' => $_COOKIE, 'server' => $_SERVER, 'session' => isset($_SESSION) && is_array($_SESSION) ? $_SESSION : null];
    }

    protected function tearDown(): void
    {
        $failure = null;
        try {
            $this->restoreApps();
        } catch (\Throwable $e) {
            $failure = $e;
        }
        $this->restoreGlobals();
        parent::tearDown();
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * restoreApp() for every app used since the last call, all of them also when one fails; then
     * ambientState() must be back to its value from before the poison.
     */
    private function restoreApps(): void
    {
        $failure = null;
        foreach ($this->apps as $app) {
            try {
                $this->restoreApp($app);
                $before = $this->ambientBefore[spl_object_id($app)] ?? null;
                if ($before !== null) {
                    $this->assertSame($before, $this->ambientState($app), 'restoreApp() did not put the app\'s own ambient state back after the poison');
                }
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        $this->apps = [];
        $this->ambientBefore = [];
        $this->restoreGlobals();
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** The superglobals as setUp() found them. */
    private function restoreGlobals(): void
    {
        if (!isset($this->globals)) {
            return;
        }
        $_COOKIE = $this->globals['cookie'];
        $_SERVER = $this->globals['server'];
        if ($this->globals['session'] === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->globals['session'];
        }
    }

    /**
     * A new app for one path of one test (app()), remembered for restoreApp(). A native session
     * the previous app's boot started is dropped unsaved first, so the next boot starts its own, as
     * the next request would.
     */
    private function freshApp(): Application
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        $app = $this->app();
        $this->apps[] = $app;

        return $app;
    }

    public function test_poisoned_ambient_state_never_reaches_the_render(): void
    {
        $ctx = $this->context();

        $this->eachPath('poison', $this->checkedPaths(), function (string $name, \Closure $render) use ($ctx): void {
            // The clean render first, on the same app: the poison stays in its services.
            $app = $this->freshApp();
            $clean = $this->rendered($this->inOuterFrame($name, $this->context(), fn (): RenderResult => $this->render($name, $render, $app, $ctx)));
            $result = $this->inOuterFrame($name, $this->poisonedVisitor(), function () use ($name, $render, $app, $ctx): RenderResult {
                $this->poison($app);

                return $this->render($name, $render, $app, $ctx);
            });

            $this->assertSame($clean, $this->rendered($result), "{$name}: the poisoned outer frame changed the render (status and body)");
            $this->assertNoMarker($name, $result);
        });
    }

    public function test_outer_frame_is_byte_equal_after_each_render(): void
    {
        $ctx = $this->context();

        $this->eachPath('frame', $this->checkedPaths(), function (string $name, \Closure $render) use ($ctx): void {
            $app = $this->freshApp();
            $this->inOuterFrame($name, $this->poisonedVisitor(), function () use ($name, $render, $app, $ctx): void {
                $this->poison($app);
                $frame = Contexts::frame();
                $depth = Contexts::depth();
                $before = $this->snapshot($app);

                $this->render($name, $render, $app, $ctx);

                $this->assertSame($frame, Contexts::frame(), "{$name}: the outer frame is current again after the render");
                $this->assertSame($depth, Contexts::depth(), "{$name}: the frame depth is back after the render");
                $this->assertSame($before, $this->snapshot($app), "{$name}: the outer frame changed");
            });
        });
    }

    public function test_interleaved_renders_are_independent(): void
    {
        $a = $this->context();
        $b = $this->other($a);

        $this->eachPath('interleave', $this->checkedPaths(), function (string $name, \Closure $render) use ($a, $b): void {
            $app = $this->freshApp();
            [$first, $second, $third] = $this->inOuterFrame($name, $b, fn (): array => [
                $this->signature($this->render($name, $render, $app, $a)),
                $this->signature($this->render($name, $render, $app, $b)),
                $this->signature($this->render($name, $render, $app, $a)),
            ]);

            $this->assertSame($first, $third, "{$name}: a render in between (another locale, viewer and read mode) changed the next one");
            $this->assertNotSame($first, $second, "{$name}: B (another locale, viewer and read mode) rendered exactly like A; the path or its page ignores the context it is given");
        });
    }

    public function test_frozen_clock_renders_identically(): void
    {
        $frozen = new \DateTimeImmutable('2026-06-15T12:00:00Z');
        $ctx = $this->context()->at($frozen);
        $format = $this->clockFormat();

        $this->eachPath('frozen', $this->checkedPaths(), function (string $name, \Closure $render) use ($ctx, $frozen, $format): void {
            $app = $this->freshApp();
            // The outer frame is at another time than the render.
            $outer = $this->context();
            $first = $this->rendered($this->inOuterFrame($name, $outer, fn (): RenderResult => $this->render($name, $render, $app, $ctx)));
            $second = $this->rendered($this->inOuterFrame($name, $outer, fn (): RenderResult => $this->render($name, $render, $app, $ctx)));

            $this->assertSame($first, $second, "{$name}: two renders of the same context frozen with at() differ");
            if ($format !== null) {
                $this->assertStringContainsString($frozen->format($format), $first, "{$name}: the render does not print its own frozen time");
            }
        });
    }

    /**
     * paths() and pending() checked: known names only.
     *
     * @return array<string, \Closure(Application, RenderContext): RenderResult>
     */
    private function checkedPaths(): array
    {
        $paths = $this->paths();
        $this->assertNotSame([], $paths, 'paths() names at least one path');
        $this->assertSame([], array_values(array_diff(array_keys($paths), self::PATHS)), 'paths() names only paths from RenderIsolationContract::PATHS');
        foreach ($paths as $name => $render) {
            $this->assertInstanceOf(\Closure::class, $render, "paths(): {$name} is a closure");
        }
        foreach ($this->pending() as $name => $tests) {
            $this->assertArrayHasKey($name, $paths, "pending() names the path {$name}, which paths() does not have");
            $this->assertIsArray($tests, "pending(): {$name} maps test names to reasons");
            foreach ($tests as $test => $reason) {
                $this->assertTrue($test === '*' || isset(self::TESTS[$test]), "pending(): {$name} names the test '{$test}'; use " . implode(', ', array_keys(self::TESTS)) . " or '*'");
                $this->assertTrue(is_string($reason) && $reason !== '', "pending(): {$name}/{$test} gives a reason");
            }
        }

        return $paths;
    }

    /**
     * Runs $check for every path. A path whose $test is not pending fails the test as usual. A
     * pending one that fails is collected, and the test is marked incomplete with it; a pending
     * one that passes fails the test once every path ran.
     *
     * @param array<string, \Closure(Application, RenderContext): RenderResult> $paths
     * @param \Closure(string, \Closure): void $check
     */
    private function eachPath(string $test, array $paths, \Closure $check): void
    {
        $pending = $this->pending();
        $passes = [];
        $fails = [];
        foreach ($paths as $name => $render) {
            $reason = $pending[$name][$test] ?? $pending[$name]['*'] ?? null;
            $failure = null;
            try {
                $check($name, $render);
            } catch (\Throwable $e) {
                $failure = $e;
            }
            // The path's app is put back before the next path runs (a failure here is never pending).
            try {
                $this->restoreApps();
            } catch (\Throwable $restore) {
                if ($failure === null) {
                    throw $restore;
                }
                // Both failed: the path's own failure first, the restore's after it.
                throw new AssertionFailedError(self::failure($failure) . "\n\nPutting the app back failed too:\n" . self::failure($restore), 0, $failure);
            }
            if ($reason === null) {
                if ($failure !== null) {
                    throw $failure;
                }
                continue;
            }
            if ($failure === null) {
                $passes[] = "{$name}/{$test} passes: remove it from pending()";
            } else {
                $fails[] = "{$name}/{$test} ({$reason}):\n" . self::failure($failure);
            }
        }
        if ($passes !== []) {
            $this->fail(implode("\n", $passes) . ($fails === [] ? '' : "\n\nStill pending:\n" . implode("\n\n", $fails)));
        }
        if ($fails !== []) {
            $this->markTestIncomplete("Still pending:\n" . implode("\n\n", $fails));
        }
    }

    /** A failure as PHPUnit shows it: the message, the comparison's diff, and where an error came from. */
    private static function failure(\Throwable $e): string
    {
        $text = $e::class . ': ' . $e->getMessage();
        if ($e instanceof ExpectationFailedException && $e->getComparisonFailure() !== null) {
            $text .= $e->getComparisonFailure()->getDiff();
        } elseif (!$e instanceof \PHPUnit\Framework\AssertionFailedError) {
            $text .= ' (' . $e->getFile() . ':' . $e->getLine() . ')';
        }

        return rtrim($text);
    }

    /**
     * $work inside an outer frame under $outer, capturing, as an internal render runs inside the
     * request that asks for it. The frame depth is back afterwards.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function inOuterFrame(string $name, RenderContext $outer, \Closure $work): mixed
    {
        $depth = Contexts::depth();
        try {
            return Contexts::run($outer->capturing(), $work);
        } finally {
            $this->assertSame($depth, Contexts::depth(), "{$name}: the frame depth is back after the outer frame");
        }
    }

    private function render(string $name, \Closure $render, Application $app, RenderContext $ctx): RenderResult
    {
        $result = $render($app, $ctx);
        $this->assertInstanceOf(RenderResult::class, $result, "paths(): {$name} returns a RenderResult");

        return $result;
    }

    /** Another locale of the site than $ctx's, or 'xx'. */
    private static function otherLocale(RenderContext $ctx): string
    {
        foreach ($ctx->site->locales as $candidate) {
            if ($candidate !== $ctx->locale) {
                return $candidate;
            }
        }

        return 'xx';
    }

    private static function viewer(string $id): Viewer
    {
        return new Viewer(['session' => new class ($id) implements Identity {
            public function __construct(private readonly string $id) {}

            public function getAuthId(): string
            {
                return $this->id;
            }
        }]);
    }

    /** Context B of the interleaved test: another locale, a signed-in viewer, working copies. */
    private function other(RenderContext $a): RenderContext
    {
        return $a->in(self::otherLocale($a))->as(self::viewer('render-isolation-viewer'))->workingCopies();
    }

    /** The visitor request the poisoned outer frame renders: the marker in its cookie, a header and its address. */
    private function poisonedRequest(): ServerRequest
    {
        $marker = $this->marker();
        $origin = rtrim($this->context()->site->origin, '/');

        return (new ServerRequest('GET', $origin . '/', ['X-Marker' => $marker, 'Cookie' => 'm=' . rawurlencode($marker)], null, '1.1', ['REMOTE_ADDR' => $marker]))
            ->withCookieParams(['m' => $marker, $marker => $marker]);
    }

    /** The outer frame's context in the poison and frame tests: everything a visitor could differ in. */
    private function poisonedVisitor(): RenderContext
    {
        $base = $this->context();

        return $base->for($this->poisonedRequest())
            ->in(self::otherLocale($base))
            ->as(self::viewer($this->marker()))
            ->workingCopies()
            ->admin('xx')
            ->at(new \DateTimeImmutable('1999-12-31T23:59:59Z'));
    }

    /** Sets the legacy ambient sources to the marker, in the current (outer) frame. */
    private function poison(Application $app): void
    {
        $marker = $this->marker();
        $_COOKIE['m'] = $marker;
        $_COOKIE[$marker] = $marker;
        $_SERVER['HTTP_X_MARKER'] = $marker;
        $_SERVER['REMOTE_ADDR'] = $marker;
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['m'] = $marker;

        $container = $app->container();
        // The visitor's request. A booted app (0.81) binds RequestContext to the current frame's
        // request, which is the poisoned outer frame's; an app without that binding gets it bound
        // as an instance, as Application::handle() bound it for every request before 0.81.
        if (!$container->bound(RequestContext::class)) {
            $container->instance(RequestContext::class, new RequestContext('/', 'GET', $this->poisonedRequest()));
        }
        $has = static fn (string $id): bool => class_exists($id) && ($container->bound($id) || $container->resolved($id));
        if ($has(Translator::class)) {
            $translator = $container->get(Translator::class);
            $translator->setLocale('xx');
            $translator->setOverrides(new class ($marker) implements TranslationOverrides {
                public function __construct(private readonly string $marker) {}

                public function get(string $locale, string $key): ?string
                {
                    return $this->marker;
                }
            });
            $translator->useOverrides(true);
        }
        if ($has(AuthManager::class)) {
            $container->get(AuthManager::class)->setUser($this->markerUser());
        }
        $live = 'Preflow\\Publishing\\LiveContent';
        if ($has($live)) {
            $container->get($live)->showWorkingCopies(true);
        }
        if ($has(AssetCollector::class)) {
            $assets = $container->get(AssetCollector::class);
            $assets->addCss('.' . $marker . ' { color: red; }', $marker);
            $assets->addJs('/* ' . $marker . ' */', JsPosition::Body, $marker);
        }
        if ($has(SeoContext::class)) {
            $container->get(SeoContext::class)->set(new PageMeta(title: $marker));
        }
        if ($has(CookieQueue::class)) {
            $container->get(CookieQueue::class)->queue('m=' . rawurlencode($marker) . '; Path=/');
        }
        $email = 'Preflow\\Notify\\Email';
        Contexts::frame()->outbox()->queue(
            class_exists($email) ? new $email($marker . '@example.invalid', $marker, $marker) : (object) ['to' => $marker],
            'mail',
        );
        $before = $this->ambientBefore[spl_object_id($app)] ??= $this->ambientState($app);
        $this->poisonApp($app);
        if ($before !== []) {
            $this->assertNotSame($before, $this->ambientState($app), 'ambientState() is the same after poisonApp(); it must read what poisonApp() sets');
        }
    }

    private function markerUser(): Authenticatable
    {
        $marker = $this->marker();

        return new class ($marker) implements Authenticatable {
            public function __construct(private readonly string $id) {}

            public function getAuthId(): string
            {
                return $this->id;
            }

            public function getPasswordHash(): string
            {
                return '';
            }

            public function getRoles(): array
            {
                return [];
            }

            public function hasRole(string $role): bool
            {
                return false;
            }
        };
    }

    /**
     * The outer frame as the render may not change it. Queued items are read by draining and
     * queueing them again, in their order. The service values (translator, user, LiveContent)
     * and the superglobals are here while the deprecated setters exist.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Application $app): array
    {
        $snapshot = ['context' => serialize(Contexts::current())];

        $outbox = Contexts::frame()->outbox();
        $items = $outbox->drain();
        foreach ($items as $item) {
            $outbox->queue($item->item, $item->channel);
        }
        $snapshot['queued'] = array_map(static fn (CapturedItem $item): string => $item->channel . ': ' . self::describe($item->item), $items);

        $container = $app->container();
        $has = static fn (string $id): bool => class_exists($id) && ($container->bound($id) || $container->resolved($id));
        if ($container->bound(CookieQueue::class)) {
            $queue = $container->get(CookieQueue::class);
            $cookies = $queue->pull();
            foreach ($cookies as $cookie) {
                $queue->queue($cookie);
            }
            $snapshot['cookies'] = $cookies;
        }
        if ($container->bound(RequestContext::class)) {
            $request = $container->get(RequestContext::class);
            $snapshot['request'] = [$request->method, $request->path, $request->request?->getCookieParams(), $request->request?->getServerParams()['REMOTE_ADDR'] ?? null];
        }
        if ($container->bound(AssetCollector::class)) {
            $assets = $container->get(AssetCollector::class);
            $snapshot['assets'] = [
                $assets->renderCss(),
                $assets->renderJsHead(),
                $assets->renderJsBody(),
                $assets->renderJsInline(),
                $assets->getCssCount(),
                $assets->getJsCount(),
            ];
        }
        if ($container->bound(SeoContext::class)) {
            $meta = $container->get(SeoContext::class)->get();
            $snapshot['seo'] = $meta === null ? null : self::describe($meta);
        }
        if ($has(Translator::class)) {
            $translator = $container->get(Translator::class);
            $snapshot['translator'] = [$translator->getLocale(), $translator->usesOverrides()];
        }
        if ($has(AuthManager::class)) {
            $auth = $container->get(AuthManager::class);
            $snapshot['user'] = [$auth->guardName(), array_map(static fn (Authenticatable $user): string => $user->getAuthId(), $auth->users())];
        }
        $live = 'Preflow\\Publishing\\LiveContent';
        if ($has($live)) {
            $snapshot['live'] = $container->get($live)->showsWorkingCopies();
        }
        $snapshot['superglobals'] = serialize([$_COOKIE, $_SERVER, $_SESSION ?? null]);
        $snapshot['session'] = [session_status(), session_id()];
        $snapshot['app'] = $this->ambientState($app);

        return $snapshot;
    }

    private function assertNoMarker(string $name, RenderResult $result): void
    {
        $marker = $this->marker();
        $this->assertStringNotContainsString($marker, (string) $result->response->getBody(), "{$name}: the body shows the ambient marker");
        foreach ($result->response->getHeaders() as $header => $values) {
            foreach ($values as $value) {
                $this->assertStringNotContainsString($marker, $value, "{$name}: the header {$header} carries the ambient marker");
            }
        }
        foreach ($result->captured as $item) {
            $this->assertStringNotContainsString($marker, self::describe($item->item), "{$name}: a captured {$item->channel} item carries the ambient marker");
        }
    }

    /** Status and body, the part of a render the poison and frozen-clock tests compare. */
    private function rendered(RenderResult $result): string
    {
        return $result->response->getStatusCode() . "\n" . $result->response->getBody();
    }

    /** Status, headers (without Date), body and captured items: what the interleaved test compares. */
    private function signature(RenderResult $result): string
    {
        $headers = $result->response->getHeaders();
        unset($headers['Date'], $headers['date']);
        ksort($headers);
        $lines = [];
        foreach ($headers as $header => $values) {
            $lines[] = $header . ': ' . implode(', ', $values);
        }
        $captured = array_map(static fn (CapturedItem $item): string => $item->channel . ': ' . self::describe($item->item), $result->captured);

        return $this->rendered($result) . "\n--- headers\n" . implode("\n", $lines) . "\n--- captured\n" . implode("\n", $captured);
    }

    private static function describe(object $value): string
    {
        try {
            return serialize($value);
        } catch (\Throwable) {
            return get_debug_type($value) . '#' . spl_object_id($value);
        }
    }
}
