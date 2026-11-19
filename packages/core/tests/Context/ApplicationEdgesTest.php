<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\SessionGuard;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\Outbox;
use Preflow\Core\Context\OutboxChannel;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SkippedWrite;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Http\CookieQueue;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\Http\RequestContext;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\DevTools\Console;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The edges where a render context starts (Application::run(), handle() without a frame, the
 * console) and the one internal-render entry, Application::render(): every request runs in its
 * own frame, cookies and after-response work live in that frame, and a render inside a request
 * gets its own.
 */
final class ApplicationEdgesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        Contexts::reset();
        EdgeRoutes::$routes = [];
        $this->dir = sys_get_temp_dir() . '/app_edges_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 'edges', 'debug' => 0, 'key' => 'app-edges-test-key-0123456789abcdef', 'url' => 'https://site.test', 'locale' => 'en'];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(EdgeRoutes::class, true) . "];\n");
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        EdgeRoutes::$routes = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_handle_opens_a_visit_frame_and_closes_it(): void
    {
        $app = $this->app();
        $seen = [];
        EdgeRoutes::$routes['/about'] = static function (ServerRequestInterface $r, Container $c) use (&$seen): ResponseInterface {
            $seen = [Contexts::depth(), Contexts::current()->purpose, Contexts::current()->request?->path, $c->get(Outbox::class) === Contexts::frame()->outbox()];

            return new Response(200, [], 'about');
        };

        $this->assertSame(0, Contexts::depth());
        $response = $app->handle(new ServerRequest('GET', 'https://site.test/about'));

        $this->assertSame('about', (string) $response->getBody());
        $this->assertSame([1, Purpose::Visit, '/about', true], $seen, 'the Outbox the container gives is the frame\'s');
        $this->assertSame(0, Contexts::depth(), 'the frame is closed after handle()');
        $this->assertSame('https://site.test', $app->site()->origin);
        $this->assertSame(['en'], $app->site()->locales);
        $this->assertSame($app->site(), $app->container()->get(Site::class));
        $this->assertInstanceOf(SystemClock::class, $app->container()->get(ClockInterface::class));
    }

    public function test_component_requests_are_fragment_renders(): void
    {
        $app = $this->app();
        $seen = [];
        EdgeRoutes::$routes['/--component/x'] = EdgeRoutes::$routes['/about'] = static function (ServerRequestInterface $r) use (&$seen): ResponseInterface {
            $ctx = Contexts::current();
            $seen[] = [$ctx->purpose, $ctx->isStorable()];

            return new Response(200, [], 'part');
        };

        $app->handle(new ServerRequest('GET', 'https://site.test/--component/x'));
        // A header-only htmx request: the client asks for a fragment of the page it shows.
        $app->handle(new ServerRequest('GET', 'https://site.test/about', ['HX-Request' => 'true']));
        $app->handle(new ServerRequest('GET', 'https://site.test/about'));

        $this->assertSame([[Purpose::Fragment, false], [Purpose::Fragment, false], [Purpose::Visit, true]], $seen);
    }

    /** run() builds the context once the request knows its hypermedia clients: a header-only fragment is never a Visit. */
    #[RunInSeparateProcess]
    public function test_run_sees_a_header_only_fragment_request_as_a_fragment(): void
    {
        $app = $this->app();
        $seen = null;
        EdgeRoutes::$routes['/about'] = static function () use (&$seen): ResponseInterface {
            $seen = [Contexts::current()->purpose, Contexts::current()->isStorable()];

            return new Response(200, [], 'part');
        };
        self::globals('/about', ['HTTP_HX_REQUEST' => 'true']);
        $app->emitUsing(static function (): void {}, static function (): void {});

        $app->run();

        $this->assertSame([Purpose::Fragment, false], $seen);
    }

    public function test_request_context_injection_reads_the_current_frame(): void
    {
        $app = $this->app();
        $paths = [];
        EdgeRoutes::$routes['/x'] = static function (ServerRequestInterface $r, Container $c) use (&$paths): ResponseInterface {
            $paths[] = $c->get(RequestContext::class)->path;

            return new Response(200, [], 'x');
        };
        EdgeRoutes::$routes['/about'] = static function (ServerRequestInterface $r, Container $c) use (&$paths, $app): ResponseInterface {
            $paths[] = $c->get(RequestContext::class)->path;
            $app->render(RenderContext::internal(Purpose::Audit, $app->site(), new SystemClock(), '/x'), new ServerRequest('GET', '/x'));
            $paths[] = $c->get(RequestContext::class)->path;

            return new Response(200, [], 'about');
        };

        $app->handle(new ServerRequest('GET', 'https://site.test/about'));

        $this->assertSame(['/about', '/x', '/about'], $paths);
        $this->assertNull($app->container()->get(RequestContext::class), 'outside every render there is no request');
    }

    public function test_render_returns_the_captured_outbox_and_skipped_writes(): void
    {
        $app = $this->app();
        EdgeRoutes::$routes['/write'] = static function (ServerRequestInterface $r, Container $c): ResponseInterface {
            Contexts::frame()->outbox()->queue(new EdgeMail('member@site.test'), 'mail');
            $c->get(CookieQueue::class)->queue('seen=1; Path=/');
            Contexts::frame()->recordSkippedWrite(new SkippedWrite('default', 'post', '1', 'save'));

            return new Response(200, [], 'written');
        };

        $ctx = RenderContext::internal(Purpose::Preview, $app->site(), new SystemClock(), '/write')->capturing();
        $result = $app->render($ctx, new ServerRequest('GET', '/write'));

        $this->assertSame('written', (string) $result->response->getBody());
        $this->assertSame(1, $result->wouldSend('mail'));
        $this->assertSame(1, $result->wouldSend('cookies'), 'a cookie the render queued is captured, not set');
        $this->assertSame([], $result->response->getHeader('Set-Cookie'));
        $this->assertSame(1, $result->wouldWrite());
        $this->assertSame("Cookie 'seen'", array_values(array_filter($result->captured, static fn ($i): bool => $i->channel === 'cookies'))[0]->summary);
        $this->assertSame(0, Contexts::depth());
    }

    /** Review Focus 2: work scheduled for after the response runs in the request's frame; a render it starts nests. */
    #[RunInSeparateProcess]
    public function test_after_response_work_runs_in_the_request_frame_and_rebuilds_nest(): void
    {
        $app = $this->app();
        $log = [];
        EdgeRoutes::$routes['/rebuilt'] = static function () use (&$log): ResponseInterface {
            $ctx = Contexts::current();
            $log[] = 'rebuild render: depth ' . Contexts::depth() . ', ' . $ctx->purpose->value . ', ' . $ctx->request?->path;

            return new Response(200, [], 'rebuilt');
        };
        EdgeRoutes::$routes['/about'] = static function () use (&$log, $app): ResponseInterface {
            $app->afterResponse(static function () use (&$log, $app): void {
                $ctx = Contexts::current();
                $log[] = 'work: depth ' . Contexts::depth() . ', ' . $ctx->purpose->value . ', ' . $ctx->request?->path;
                $result = $app->render(RenderContext::internal(Purpose::Rebuild, $app->site(), new SystemClock(), '/rebuilt'), new ServerRequest('GET', '/rebuilt'));
                $log[] = 'rendered ' . $result->response->getBody() . '; back at depth ' . Contexts::depth() . ', ' . Contexts::current()->purpose->value . ', ' . Contexts::current()->request?->path;
            });

            return new Response(200, [], 'about');
        };
        self::globals('/about');
        $app->emitUsing(static function (ResponseInterface $r) use (&$log): void {
            $log[] = 'emitted ' . $r->getBody();
        }, static function () use (&$log): void {
            $log[] = 'client released';
        });

        $app->run();

        $this->assertSame([
            'emitted about',
            'client released',
            'work: depth 1, visit, /about',
            'rebuild render: depth 2, rebuild, /rebuilt',
            'rendered rebuilt; back at depth 1, visit, /about',
        ], $log);
        $this->assertSame(0, Contexts::depth());
    }

    /** Regression for the dropped-cookie leak (InternalRenderCookieTest's scenario, through render()). */
    #[RunInSeparateProcess]
    public function test_an_internal_render_cannot_take_the_outer_requests_cookies(): void
    {
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => SessionGuard::class, 'provider' => 'users']],
            'providers' => ['users' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['id' => 'admin', 'email' => 'admin@site.test', 'password_hash' => '', 'roles' => []],
            ]]],
            'session' => ['cookie' => 'edges', 'secure' => false],
        ], true) . ";\n");
        $app = $this->app();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $_SESSION['_auth_user_id'] = (string) (new \Preflow\Auth\ConfigUserProvider([['email' => 'admin@site.test', 'password_hash' => '']]))
            ->findByCredentials(['email' => 'admin@site.test'])?->getAuthId();
        $inner = null;
        EdgeRoutes::$routes['/about'] = static fn (): ResponseInterface => new Response(200, [], 'about');
        EdgeRoutes::$routes['/step'] = static function () use ($app, &$inner): ResponseInterface {
            $inner = $app->render(RenderContext::internal(Purpose::Audit, $app->site(), new SystemClock(), '/about'), new ServerRequest('GET', '/about'));

            return new Response(200, [], 'rendered ' . $inner->response->getStatusCode());
        };
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://site.test/step')->withHeader('Cookie', 'edges=' . session_id());

        $response = $app->handle($request);

        $this->assertSame('rendered 200', (string) $response->getBody());
        $this->assertContains(SessionGuard::SIGNED_IN_COOKIE, self::cookieNames($response), 'the marker the request queued reaches its response; the render in its own frame could not pull it');
    }

    public function test_kernel_error_path_applies_only_its_own_frames_cookies(): void
    {
        $app = $this->app();
        EdgeRoutes::$routes['/boom'] = static function (ServerRequestInterface $r, Container $c): ResponseInterface {
            $c->get(CookieQueue::class)->queue('inner=1; Path=/');
            throw new \RuntimeException('render failed');
        };

        $outer = RenderContext::fromRequest(new ServerRequest('GET', 'https://site.test/admin'), $app->site(), new SystemClock());
        [$result, $left] = Contexts::run($outer, static function () use ($app): array {
            $queue = $app->container()->get(CookieQueue::class);
            $queue->queue('outer=1; Path=/');
            $result = $app->render(Contexts::current()->for(new ServerRequest('GET', 'https://site.test/boom'))->capturing(), new ServerRequest('GET', 'https://site.test/boom'));

            return [$result, $queue->pull()];
        });

        $this->assertSame(500, $result->response->getStatusCode());
        $this->assertSame(['inner'], self::cookieNames($result->response), 'the error page carries the failed render\'s own cookie');
        $this->assertSame(['outer=1; Path=/'], $left, 'the outer frame keeps its cookie');
    }

    public function test_cookies_left_at_the_end_of_a_request_reach_its_response(): void
    {
        $app = $this->app();
        EdgeRoutes::$routes['/nested'] = static function (ServerRequestInterface $r, Container $c): ResponseInterface {
            $c->get(CookieQueue::class)->queue('nested=1; Path=/');

            return new Response(200, [], 'nested');
        };
        EdgeRoutes::$routes['/about'] = static function (ServerRequestInterface $r, Container $c) use ($app): ResponseInterface {
            $c->get(CookieQueue::class)->queue('outer=1; Path=/');
            // A nested render that delivers hands its cookie to this request.
            $app->render(Contexts::current()->for(new ServerRequest('GET', '/nested')), new ServerRequest('GET', '/nested'));

            return new Response(200, [], 'about');
        };

        $response = $app->handle(new ServerRequest('GET', 'https://site.test/about'));

        $this->assertSame(['outer', 'nested'], self::cookieNames($response));
        $this->assertSame(['nested'], self::cookieNames($app->handle(new ServerRequest('GET', 'https://site.test/nested'))), 'the next request sets only its own');
    }

    #[RunInSeparateProcess]
    public function test_console_commands_run_in_a_console_frame(): void
    {
        $console = new Console($this->dir);
        $console->register(new class () implements CommandInterface {
            public function getName(): string
            {
                return 'context:show';
            }

            public function getDescription(): string
            {
                return 'Prints the purpose of the current context';
            }

            public function execute(array $args): int
            {
                echo Contexts::current()->purpose->value . ' ' . Contexts::depth() . ' ' . Contexts::current()->site->origin;
                Contexts::frame()->outbox()->channel($GLOBALS['edgeChannel']);
                Contexts::frame()->outbox()->queue(new EdgeMail('member@site.test'), 'mail');
                Contexts::frame()->outbox()->afterResponse(static function (): void {
                    echo ' (a command has no after the response)';
                });

                return 0;
            }
        });
        $GLOBALS['edgeChannel'] = $channel = new EdgeChannel();

        ob_start();
        $code = $console->run(['preflow', 'context:show']);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertSame('console 1 https://site.test', $out, 'work for after the response is dropped, as before 0.81');
        $this->assertCount(1, $channel->delivered, 'what the command queued is sent');
        $this->assertSame(0, Contexts::depth());
    }

    public function test_internal_render_is_reads_the_context(): void
    {
        $app = $this->app();
        $seen = [];
        EdgeRoutes::$routes['/about'] = static function () use (&$seen): ResponseInterface {
            $seen[] = InternalRender::is(new ServerRequest('GET', '/anything'));

            return new Response(200, [], 'about');
        };

        $app->render(RenderContext::internal(Purpose::Audit, $app->site(), new SystemClock(), '/about'), new ServerRequest('GET', '/about'));
        $app->handle(new ServerRequest('GET', 'https://site.test/about'));

        $this->assertSame([true, false], $seen);
        $this->assertFalse(InternalRender::is(new ServerRequest('GET', '/')), 'outside every render: the attribute decides');
        $this->assertTrue(InternalRender::is((new ServerRequest('GET', '/'))->withAttribute(InternalRender::ATTRIBUTE, true)));
    }

    public function test_internal_render_mark_is_deprecated(): void
    {
        Deprecations::reset();
        $notices = self::deprecations(static function (): void {
            InternalRender::mark(new ServerRequest('GET', '/'));
        });

        $this->assertSame(['Preflow\Core\Http\InternalRender::mark() is deprecated since 0.81 and is removed in 0.83; use Application::render() with RenderContext::internal().'], $notices);
    }

    public function test_cookie_queue_isolated_is_a_deprecated_shim_bound_to_its_frame(): void
    {
        Deprecations::reset();
        $app = $this->app();
        $queue = $app->container()->get(CookieQueue::class);
        $ctx = RenderContext::fromRequest(new ServerRequest('GET', 'https://site.test/'), $app->site(), new SystemClock());

        $seen = [];
        $notices = self::deprecations(static function () use ($queue, $ctx, &$seen): void {
            Contexts::run($ctx, static function () use ($queue, &$seen): void {
                $queue->queue('outer=1');
                $inside = $queue->isolated(static function () use ($queue, &$seen): string {
                    $seen[] = $queue->has('outer') ? 'outer visible' : 'starts empty';
                    $queue->queue('inner=1');
                    $seen[] = 'same frame: ' . (Contexts::depth() === 1 ? 'yes' : 'no');

                    return 'done';
                });
                $seen[] = $inside;
                $seen[] = implode(',', $queue->pull());
                // Another frame has its own queue, untouched by this one.
                Contexts::run(Contexts::current(), static function () use ($queue, &$seen): void {
                    $seen[] = 'nested: ' . implode(',', $queue->pull());
                });
            });
        });

        $this->assertSame(['starts empty', 'same frame: yes', 'done', 'outer=1', 'nested: '], $seen);
        $this->assertSame(['Preflow\Core\Http\CookieQueue::isolated() is deprecated since 0.81 and is removed in 0.83; use Application::render() (a render in its own frame starts with its own cookies).'], $notices);
    }

    /** A failed visitor request still sends the mail it queued (0.80's behaviour); the error page renders. */
    #[RunInSeparateProcess]
    public function test_mail_queued_before_a_failure_is_sent_once_and_the_error_page_renders(): void
    {
        $this->notify();
        $app = $this->app();
        EdgeRoutes::$routes['/signup'] = static function (ServerRequestInterface $r, Container $c): ResponseInterface {
            $c->get(Notifier::class)->sendLater(new Email('member@site.test', 'Welcome', 'Your account is ready.'));
            throw new \RuntimeException('the page failed after the account was saved');
        };

        $response = $app->handle(new ServerRequest('GET', 'https://site.test/signup'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertCount(1, $app->container()->get(TransportFactory::class)->get('mail')->sent());
    }

    /** When handle() itself fails, what its frame queued is still sent once, before the exception goes on. */
    #[RunInSeparateProcess]
    public function test_a_request_that_throws_still_sends_what_it_queued(): void
    {
        $this->notify();
        $app = $this->app();
        $channel = new EdgeChannel();
        $app->onRequest(static function (ServerRequestInterface $request) use ($app, $channel): void {
            if ($request->getUri()->getPath() !== '/about') {
                return;
            }
            Contexts::frame()->outbox()->channel($channel);
            Contexts::frame()->outbox()->queue(new EdgeMail('member@site.test'), 'mail');
            $app->container()->get(Notifier::class)->sendLater(new Email('member@site.test', 'Welcome', 'Your account is ready.'));
            throw new \RuntimeException('setup failed');
        });

        try {
            $app->handle(new ServerRequest('GET', 'https://site.test/about'));
            $this->fail('the request must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('setup failed', $e->getMessage());
        }

        $this->assertCount(1, $channel->delivered);
        $this->assertCount(1, $app->container()->get(TransportFactory::class)->get('mail')->sent());
        $this->assertSame(0, Contexts::depth());

        // An internal render that throws keeps dropping what it queued.
        $render = static fn () => $app->render(RenderContext::internal(Purpose::Audit, $app->site(), new SystemClock(), '/x'), new ServerRequest('GET', '/x'));
        EdgeRoutes::$routes['/x'] = static function (): never {
            Contexts::frame()->outbox()->queue(new EdgeMail('member@site.test'), 'mail');
            throw new \RuntimeException('audit failed');
        };
        $render();
        $this->assertCount(1, $channel->delivered);
    }

    /** At depth 0 a failing outbox channel is logged: the response stands and the after-response work still runs. */
    #[RunInSeparateProcess]
    public function test_a_failing_outbox_channel_is_logged_and_the_work_after_the_response_still_runs(): void
    {
        $log = $this->dir . '/error.log';
        ini_set('error_log', $log);
        $app = $this->app();
        $app->runAfterResponseInHandle();
        $ran = false;
        EdgeRoutes::$routes['/about'] = static function () use ($app, &$ran): ResponseInterface {
            Contexts::frame()->outbox()->channel(new EdgeChannel(fail: true));
            Contexts::frame()->outbox()->queue(new EdgeMail('member@site.test'), 'mail');
            $app->afterResponse(static function () use (&$ran): void {
                $ran = true;
            });

            return new Response(200, [], 'about');
        };

        $response = $app->handle(new ServerRequest('GET', 'https://site.test/about'));

        $this->assertSame('about', (string) $response->getBody());
        $this->assertTrue($ran, 'the after-response work ran');
        $this->assertStringContainsString('the mail server is down', (string) @file_get_contents($log));
    }

    /** Without runAfterResponseInHandle(), an in-process request has no "after the response": its work is dropped (0.80). */
    public function test_handle_drops_after_response_work_unless_asked_to_run_it(): void
    {
        $app = $this->app();
        $ran = 0;
        EdgeRoutes::$routes['/about'] = static function () use ($app, &$ran): ResponseInterface {
            $app->afterResponse(static function () use (&$ran): void {
                $ran++;
            });

            return new Response(200, [], 'about');
        };

        $app->handle(new ServerRequest('GET', 'https://site.test/about'));
        $this->assertSame(0, $ran);
        $app->runAfterResponseInHandle();
        $app->handle(new ServerRequest('GET', 'https://site.test/about'));
        $this->assertSame(1, $ran);
    }

    #[RunInSeparateProcess]
    public function test_debug_mode_comes_from_the_app(): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 'edges', 'debug' => 1, 'key' => 'app-edges-test-key-0123456789abcdef', 'url' => 'https://site.test'];\n");
        $assertions = ini_get('zend.assertions');
        ini_set('zend.assertions', '0');
        $app = $this->app();
        try {
            Contexts::run(RenderContext::console($app->site(), new SystemClock()), static function (): void {
                Contexts::reset();
            });
            $this->fail('an unbalanced stack throws in a debug app');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('out of balance', $e->getMessage());
        } finally {
            ini_set('zend.assertions', (string) $assertions);
        }
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    private function notify(): void
    {
        file_put_contents($this->dir . '/config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@site.test']]];\n");
    }

    /** @param array<string, string> $server */
    private static function globals(string $path, array $server = []): void
    {
        $_SERVER = $server + [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'HTTP_HOST' => 'site.test',
            'HTTPS' => 'on',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REMOTE_ADDR' => '192.0.2.10',
        ];
        $_GET = $_POST = $_COOKIE = $_FILES = [];
    }

    /** @return list<string> the names of the cookies the response sets */
    private static function cookieNames(ResponseInterface $response): array
    {
        return array_map(static fn (string $c): string => strstr($c, '=', true) ?: $c, $response->getHeader('Set-Cookie'));
    }

    /** @return list<string> the deprecation messages $fn raised */
    private static function deprecations(\Closure $fn): array
    {
        $notices = [];
        set_error_handler(static function (int $no, string $message) use (&$notices): bool {
            $notices[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $fn();
        } finally {
            restore_error_handler();
        }

        return $notices;
    }
}

/** Answers the paths a test registers, else passes the request on. */
final class EdgeRoutes implements MiddlewareInterface
{
    /** @var array<string, \Closure(ServerRequestInterface, Container): ResponseInterface> */
    public static array $routes = [];

    public function __construct(private readonly Container $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = self::$routes[$request->getUri()->getPath()] ?? null;

        return $route !== null ? $route($request, $this->container) : $handler->handle($request);
    }
}

final readonly class EdgeMail
{
    public function __construct(public string $to) {}
}

final class EdgeChannel implements OutboxChannel
{
    /** @var list<object> */
    public array $delivered = [];

    public function __construct(private readonly bool $fail = false) {}

    public function name(): string
    {
        return 'mail';
    }

    public function deliver(array $items): void
    {
        if ($this->fail) {
            throw new \RuntimeException('the mail server is down');
        }
        array_push($this->delivered, ...$items);
    }

    public function describe(object $item): string
    {
        return 'Mail to ' . ($item instanceof EdgeMail ? $item->to : '?');
    }
}
