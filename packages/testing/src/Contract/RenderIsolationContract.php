<?php

declare(strict_types=1);

namespace Preflow\Testing\Contract;

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
use Preflow\Core\Identity;
use Preflow\I18n\Translator;
use Preflow\Testing\ContextFactory;
use Preflow\View\AssetCollector;
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
 * - app(): a booted application. Called once per test; return a new one each time (the tests
 *   change its services).
 * - paths(): path name => a closure that renders under the context it is given and returns the
 *   RenderResult (the response, what its frame captured, the writes it skipped). Names are from
 *   PATHS: audit, geo, rebuild, verify, preview, mail, pdf; cover the ones the app has.
 * - pending() (optional): paths that do not render under their context yet. They still run in
 *   every test; the test is then marked incomplete (never skipped) and lists, for each pending
 *   path, why it fails, or that it passes now and must leave pending(). A path not pending must
 *   pass.
 * - marker() (optional): the text planted in the ambient state; no render may print it.
 * - context() (optional): the context every render starts from (ContextFactory::forTest()); give
 *   your site's origin and locales here.
 *
 * Each render runs inside an outer frame of its own (the test's, under the context() capturing),
 * as an internal render runs inside the request that asks for it. The tests:
 * - poisoned ambient state: in the outer frame, the cookie `m`, $_COOKIE[marker], the header
 *   X-Marker ($_SERVER['HTTP_X_MARKER']) and $_SESSION['m'] are set to the marker; where the app
 *   has them, the translator's deprecated setLocale('xx'), AuthManager::setUser() with a user whose
 *   id is the marker and LiveContent::showWorkingCopies() are called; one cookie (CookieQueue) and
 *   one mail (the frame's outbox, channel 'mail') are queued. The render's body, headers and
 *   captured items must not contain the marker, and its status and body must equal the same
 *   render's without the poison;
 * - the outer frame byte-equal: the same poison, then a snapshot of the outer frame before and
 *   after the render: the current context (serialize(), which leaves the PSR request out), the
 *   frame's queued items (cookies and mail), the CookieQueue, the asset registry and the SEO page
 *   meta. The render must also leave the same frame current at the same depth;
 * - interleaved renders: A, B, A in one outer frame, B with another locale, a signed-in viewer
 *   and working copies; both A renders give the same status and body;
 * - a frozen clock: two renders of the same context frozen with at() give the same status and
 *   body. A page that prints a nonce needs the nonce made deterministic for this test.
 *
 * Superglobals are restored after each test.
 *
 * @since 0.81.0
 */
abstract class RenderIsolationContract extends TestCase
{
    /** The internal render paths a runner may cover. */
    public const array PATHS = ['audit', 'geo', 'rebuild', 'verify', 'preview', 'mail', 'pdf'];

    /** A booted application; called once per test. */
    abstract protected function app(): Application;

    /**
     * @return array<string, \Closure(Application, RenderContext): RenderResult> path name (from PATHS) => renderer
     */
    abstract protected function paths(): array;

    /** @return list<string> paths not on the render context yet: run, and the test marked incomplete */
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

    /** @var array{cookie: array<mixed>, server: array<mixed>, session: array<mixed>|null} */
    private array $globals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->globals = ['cookie' => $_COOKIE, 'server' => $_SERVER, 'session' => isset($_SESSION) && is_array($_SESSION) ? $_SESSION : null];
    }

    protected function tearDown(): void
    {
        if (isset($this->globals)) {
            $_COOKIE = $this->globals['cookie'];
            $_SERVER = $this->globals['server'];
            if ($this->globals['session'] === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $this->globals['session'];
            }
        }
        parent::tearDown();
    }

    public function test_poisoned_ambient_state_never_reaches_the_render(): void
    {
        $app = $this->app();
        $ctx = $this->context();
        $paths = $this->checkedPaths();
        // Every clean render first: the poison stays in the app's services.
        $clean = [];
        $failed = [];
        foreach ($paths as $name => $render) {
            try {
                $clean[$name] = $this->rendered($this->inOuterFrame($name, fn (): RenderResult => $this->render($name, $render, $app, $ctx)));
            } catch (\Throwable $e) {
                $failed[$name] = $e;
            }
        }

        $this->eachPath($paths, function (string $name, \Closure $render) use ($app, $ctx, $clean, $failed): void {
            if (isset($failed[$name])) {
                throw $failed[$name];
            }
            $result = $this->inOuterFrame($name, function () use ($name, $render, $app, $ctx): RenderResult {
                $this->poison($app);

                return $this->render($name, $render, $app, $ctx);
            });

            $this->assertNoMarker($name, $result);
            $this->assertSame($clean[$name], $this->rendered($result), "{$name}: the poisoned outer frame changed the render (status and body)");
        });
    }

    public function test_outer_frame_is_byte_equal_after_each_render(): void
    {
        $app = $this->app();
        $ctx = $this->context();

        $this->eachPath($this->checkedPaths(), function (string $name, \Closure $render) use ($app, $ctx): void {
            $this->inOuterFrame($name, function () use ($name, $render, $app, $ctx): void {
                $this->poison($app);
                $frame = Contexts::frame();
                $depth = Contexts::depth();
                $before = $this->snapshot($app);

                $this->render($name, $render, $app, $ctx);

                $this->assertSame($frame, Contexts::frame(), "{$name}: the outer frame is current again after the render");
                $this->assertSame($depth, Contexts::depth(), "{$name}: the frame depth is back after the render");
                $this->assertSame($before, $this->snapshot($app), "{$name}: the outer frame changed (context, queued items, cookies, assets, page meta)");
            });
        });
    }

    public function test_interleaved_renders_are_independent(): void
    {
        $app = $this->app();
        $a = $this->context();
        $b = $this->other($a);

        $this->eachPath($this->checkedPaths(), function (string $name, \Closure $render) use ($app, $a, $b): void {
            [$first, $third] = $this->inOuterFrame($name, function () use ($name, $render, $app, $a, $b): array {
                $first = $this->rendered($this->render($name, $render, $app, $a));
                $this->render($name, $render, $app, $b);

                return [$first, $this->rendered($this->render($name, $render, $app, $a))];
            });

            $this->assertSame($first, $third, "{$name}: a render in between (another locale, viewer and read mode) changed the next one");
        });
    }

    public function test_frozen_clock_renders_identically(): void
    {
        $app = $this->app();
        $ctx = $this->context()->at(new \DateTimeImmutable('2026-06-15T12:00:00Z'));

        $this->eachPath($this->checkedPaths(), function (string $name, \Closure $render) use ($app, $ctx): void {
            $first = $this->rendered($this->inOuterFrame($name, fn (): RenderResult => $this->render($name, $render, $app, $ctx)));
            $second = $this->rendered($this->inOuterFrame($name, fn (): RenderResult => $this->render($name, $render, $app, $ctx)));

            $this->assertSame($first, $second, "{$name}: two renders of the same context frozen with at() differ");
        });
    }

    /**
     * paths() checked: known names only, pending() a part of them.
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
        $this->assertSame([], array_values(array_diff($this->pending(), array_keys($paths))), 'pending() names only paths that paths() has');

        return $paths;
    }

    /**
     * Runs $check for every path. A path not pending fails the test as usual; a pending path's
     * outcome is collected, and the test is marked incomplete with it.
     *
     * @param array<string, \Closure(Application, RenderContext): RenderResult> $paths
     * @param \Closure(string, \Closure): void $check
     */
    private function eachPath(array $paths, \Closure $check): void
    {
        $pending = $this->pending();
        $outcomes = [];
        foreach ($paths as $name => $render) {
            if (!in_array($name, $pending, true)) {
                $check($name, $render);
                continue;
            }
            try {
                $check($name, $render);
                $outcomes[] = "{$name}: passes this test (once it passes all four, remove it from pending())";
            } catch (\Throwable $e) {
                $message = trim(strtok($e->getMessage(), "\n") ?: '');
                $outcomes[] = "{$name}: " . $e::class . ($message === '' ? '' : ': ' . $message);
            }
        }
        if ($outcomes !== []) {
            $this->markTestIncomplete("Paths not on the render context yet (pending()):\n" . implode("\n", $outcomes));
        }
    }

    /**
     * $work inside an outer frame under the context(), capturing, as an internal render runs
     * inside the request that asks for it. The frame depth is back afterwards.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function inOuterFrame(string $name, \Closure $work): mixed
    {
        $depth = Contexts::depth();
        try {
            return Contexts::run($this->context()->capturing(), $work);
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

    /** Context B of the interleaved test: another locale, a signed-in viewer, working copies. */
    private function other(RenderContext $a): RenderContext
    {
        $locale = 'xx';
        foreach ($a->site->locales as $candidate) {
            if ($candidate !== $a->locale) {
                $locale = $candidate;
                break;
            }
        }
        $viewer = new Viewer(['session' => new class implements Identity {
            public function getAuthId(): string
            {
                return 'render-isolation-viewer';
            }
        }]);

        return $a->in($locale)->as($viewer)->workingCopies();
    }

    /** Sets the legacy ambient sources to the marker, in the current (outer) frame. */
    private function poison(Application $app): void
    {
        $marker = $this->marker();
        $_COOKIE['m'] = $marker;
        $_COOKIE[$marker] = $marker;
        $_SERVER['HTTP_X_MARKER'] = $marker;
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['m'] = $marker;

        $container = $app->container();
        $has = static fn (string $id): bool => class_exists($id) && ($container->bound($id) || $container->resolved($id));
        if ($has(Translator::class)) {
            $container->get(Translator::class)->setLocale('xx');
        }
        if ($has(AuthManager::class)) {
            $container->get(AuthManager::class)->setUser($this->markerUser());
        }
        $live = 'Preflow\\Publishing\\LiveContent';
        if ($has($live)) {
            $container->get($live)->showWorkingCopies(true);
        }
        if ($has(CookieQueue::class)) {
            $container->get(CookieQueue::class)->queue('m=' . rawurlencode($marker) . '; Path=/');
        }
        $email = 'Preflow\\Notify\\Email';
        Contexts::frame()->outbox()->queue(
            class_exists($email) ? new $email($marker . '@example.invalid', $marker, $marker) : (object) ['to' => $marker],
            'mail',
        );
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
     * queueing them again, in their order.
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
        if ($container->bound(CookieQueue::class)) {
            $queue = $container->get(CookieQueue::class);
            $cookies = $queue->pull();
            foreach ($cookies as $cookie) {
                $queue->queue($cookie);
            }
            $snapshot['cookies'] = $cookies;
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

    /** Status and body, the part of a render the tests compare. */
    private function rendered(RenderResult $result): string
    {
        return $result->response->getStatusCode() . "\n" . $result->response->getBody();
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
