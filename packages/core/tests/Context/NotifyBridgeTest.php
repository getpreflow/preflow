<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Context\CapturedItem;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Context\SystemClock;
use Preflow\Notify\Email;
use Preflow\Notify\Mail\MailContent;
use Preflow\Notify\Mail\Mailer;
use Preflow\Notify\Notifier;
use Preflow\Notify\SentMessage;
use Preflow\Notify\TransportFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Core's bridges to notify's seams: Notifier::sendLater() and send() follow the current frame's
 * outbox (a discarding frame drops, a capturing one lists what it would send and sends nothing, a
 * delivering one sends when its request ends), and a composed mail renders in its recipient's
 * context.
 */
final class NotifyBridgeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        Contexts::reset();
        BridgeRoutes::$routes = [];
        $this->dir = sys_get_temp_dir() . '/notify_bridge_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 'bridge', 'debug' => 0, 'engine' => 'twig', 'key' => 'notify-bridge-test-key-0123456789abcdef', 'url' => 'https://site.test', 'locale' => 'en'];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(BridgeRoutes::class, true) . "];\n");
        file_put_contents($this->dir . '/config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@site.test']]];\n");
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        BridgeRoutes::$routes = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** Dropped in a discarding frame, a nested run inside it, a discarding or capturing within() stretch and a nested run inside that. */
    #[RunInSeparateProcess]
    public function test_send_later_in_a_discarding_frame_is_dropped(): void
    {
        $app = $this->app();
        $notifier = $app->container()->get(Notifier::class);
        $mail = static fn (string $to): Email => new Email($to . '@site.test', 'Hello', 'Text');
        $console = RenderContext::console($app->site(), new SystemClock());

        Contexts::run($console, static function () use ($notifier, $mail, $console): void {
            Contexts::run($console->discarding(), static function () use ($notifier, $mail, $console): void {
                $notifier->sendLater($mail('discarding-frame'));
                Contexts::run($console, static fn () => $notifier->sendLater($mail('run-inside-discarding')));
            });
            Contexts::within(Contexts::current()->discarding(), static function () use ($notifier, $mail, $console): void {
                $notifier->sendLater($mail('discarding-stretch'));
                Contexts::run($console, static fn () => $notifier->sendLater($mail('run-inside-stretch')));
            });
            Contexts::within(Contexts::current()->capturing(), static function () use ($notifier, $mail, $console): void {
                $notifier->sendLater($mail('capturing-stretch'));
                Contexts::run($console, static fn () => $notifier->sendLater($mail('run-inside-capturing-stretch')));
            });
            $notifier->sendLater($mail('console'));
            Contexts::deliverQueued();
        });

        $this->assertSame(['console@site.test'], $this->sentTo($app));
        $this->assertSame(0, Contexts::depth());

        // An internal render (purpose Audit: nothing sent) of a page that queues a mail.
        BridgeRoutes::$routes['/page'] = static function (ServerRequestInterface $r, Container $c) use ($mail): ResponseInterface {
            $c->get(Notifier::class)->sendLater($mail('page'));

            return new Response(200, [], 'page');
        };
        $result = $app->render(RenderContext::internal(Purpose::Audit, $app->site(), new SystemClock(), '/page'), new ServerRequest('GET', 'https://site.test/page'));
        $this->assertSame('page', (string) $result->response->getBody());
        $this->assertSame([], $result->captured);
        $this->assertSame(0, $notifier->flushDeferred());
        $this->assertSame(['console@site.test'], $this->sentTo($app));
    }

    /** Regression: send() during an internal render went out at once; a preview or a role view lists it instead. */
    #[RunInSeparateProcess]
    public function test_send_now_in_a_capturing_frame_is_captured_not_delivered(): void
    {
        $app = $this->app();
        $answers = [];
        BridgeRoutes::$routes['/signup'] = static function (ServerRequestInterface $r, Container $c) use (&$answers): ResponseInterface {
            $answers[] = $c->get(Notifier::class)->send(new Email('now@site.test', 'Now', 'Sent at once'))->transport;
            $answers[] = $c->get(Mailer::class)->send(new MailContent('Composed', 'Text'), 'composed@site.test')->transport;
            $c->get(Notifier::class)->sendLater(new Email('later@site.test', 'Later', 'Queued'));

            return new Response(200, [], 'signed up');
        };
        $request = new ServerRequest('GET', 'https://site.test/signup');
        $visit = RenderContext::fromRequest($request, $app->site(), new SystemClock());

        $preview = $app->render($visit->capturing()->skippingWrites()->because(Purpose::Preview), $request);
        $role = $app->render($visit->asRole(new RoleView('member', ['member'])), $request);
        $audit = $app->render(RenderContext::internal(Purpose::Audit, $app->site(), new SystemClock(), '/signup'), $request);

        $this->assertSame([], $this->sentTo($app), 'nothing leaves a preview, a role view or an audit');
        // The callers take the path they take on a real visit.
        $this->assertSame(['mail', 'mail', 'mail', 'mail', 'mail', 'mail'], $answers);
        foreach (['preview' => $preview, 'role view' => $role] as $name => $result) {
            $this->assertSame('signed up', (string) $result->response->getBody());
            $this->assertSame(3, $result->wouldSend('mail'), $name);
            $summaries = array_map(static fn (CapturedItem $i): string => $i->summary, $result->captured);
            $this->assertStringContainsString('now@site.test', $summaries[0], $name);
            $this->assertStringContainsString('composed@site.test', $summaries[1], $name);
            $this->assertStringContainsString('later@site.test', $summaries[2], $name);
        }
        $this->assertSame([], $audit->captured);

        // A visitor's request of the same page sends all three, the queued one after the response.
        $app->handle($request);
        $this->assertSame(['now@site.test', 'composed@site.test', 'later@site.test'], $this->sentTo($app));
    }

    /** With core, Notifier::discardingQueued() is a deprecated shim over the frame: the discard holds for its stretch only. */
    #[RunInSeparateProcess]
    public function test_discarding_queued_is_a_deprecated_shim_with_core(): void
    {
        $app = $this->app();
        $notifier = $app->container()->get(Notifier::class);
        Deprecations::reset();
        $notices = [];
        set_error_handler(static function (int $no, string $message) use (&$notices): bool {
            $notices[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            Contexts::run(RenderContext::console($app->site(), new SystemClock()), static function () use ($notifier): void {
                foreach (['a', 'b'] as $name) {
                    $result = $notifier->discardingQueued(static function () use ($notifier, $name): string {
                        $notifier->sendLater(new Email('dropped-' . $name . '@site.test', 'S', 'T'));
                        $notifier->send(new Email('dropped-now-' . $name . '@site.test', 'S', 'T'));

                        return 'rendered';
                    });
                    \assert($result === 'rendered');
                }
                $notifier->sendLater(new Email('kept@site.test', 'S', 'T'));
                Contexts::deliverQueued();
            });
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['kept@site.test'], $this->sentTo($app));
        $this->assertSame(['Preflow\Notify\Notifier::discardingQueued() is deprecated since 0.81 and is removed in 0.83; use Application::render() or Contexts::run() with RenderContext::discarding().'], $notices);
    }

    /** The layout prints current_locale(): a mail to a reader of 'fr', composed in an admin request in 'de', is in 'fr'; the frame stays 'de'. */
    #[RunInSeparateProcess]
    public function test_compose_runs_in_the_recipients_context(): void
    {
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de', 'fr'], 'fallback' => 'en', 'url_strategy' => 'none', 'cookie' => false];\n");
        file_put_contents($this->dir . '/mail-locale.twig', '{{ current_locale() }}|{{ lang }}|{{ subject }}');
        $app = $this->app();
        $mailer = $app->container()->get(Mailer::class);
        $admin = RenderContext::fromRequest(new ServerRequest('GET', 'https://site.test/admin'), $app->site(), new SystemClock())->in('de')->admin('de');

        [$html, $after, $depth] = Contexts::run($admin, function () use ($mailer): array {
            $email = $mailer->compose(new MailContent('Bonjour', 'Texte', 'fr'), 'reader@site.test', template: $this->dir . '/mail-locale.twig');

            return [(string) $email->html, Contexts::current()->locale . ' ' . (Contexts::current()->surface->admin ? 'admin' : 'public'), Contexts::depth()];
        });

        $this->assertSame('fr|fr|Bonjour', $html);
        $this->assertSame('de admin', $after, 'the frame around it is untouched');
        $this->assertSame(1, $depth);
    }

    /** A request of its own inside a command (handle()) that fails after queueing a mail: the mail goes out once, with the command's. */
    #[RunInSeparateProcess]
    public function test_mail_a_nested_request_queued_before_its_failure_goes_out_once(): void
    {
        $app = $this->app();
        $notifier = $app->container()->get(Notifier::class);
        $app->onRequest(static function (ServerRequestInterface $request) use ($notifier): void {
            if ($request->getUri()->getPath() === '/fail') {
                $notifier->sendLater(new Email('nested@site.test', 'Welcome', 'Your account is ready.'));
                throw new \RuntimeException('the request failed after the account was saved');
            }
        });

        $failed = Contexts::run(RenderContext::console($app->site(), new SystemClock()), static function () use ($app, $notifier): string {
            try {
                $app->handle(new ServerRequest('GET', 'https://site.test/fail'));

                return 'no failure';
            } catch (\RuntimeException $e) {
                $notifier->sendLater(new Email('command@site.test', 'Done', 'The command ran.'));
                Contexts::deliverQueued();

                return $e->getMessage();
            }
        });

        $this->assertSame('the request failed after the account was saved', $failed);
        $this->assertSame(['nested@site.test', 'command@site.test'], $this->sentTo($app));
        $this->assertSame(0, $notifier->flushDeferred());
    }

    /** A command sends what it queued now with flushDeferred() and learns how many went out; nothing goes out twice. */
    #[RunInSeparateProcess]
    public function test_flush_deferred_in_a_command_sends_its_mail_now_once(): void
    {
        $app = $this->app();
        $notifier = $app->container()->get(Notifier::class);

        $counts = Contexts::run(RenderContext::console($app->site(), new SystemClock()), function () use ($app, $notifier): array {
            $notifier->sendLater(new Email('invite@site.test', 'Invite', 'Your link.'));
            $first = $notifier->flushDeferred();
            $sentThen = count($this->sentTo($app));
            Contexts::deliverQueued();

            return [$first, $sentThen, $notifier->flushDeferred()];
        });

        $this->assertSame([1, 1, 0], $counts);
        $this->assertSame(['invite@site.test'], $this->sentTo($app));
    }

    /** A transport that fails while the request's mail goes out is logged; the response stands. */
    #[RunInSeparateProcess]
    public function test_a_failing_send_is_logged_and_never_breaks_the_response(): void
    {
        file_put_contents($this->dir . '/config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'missing']]];\n");
        $log = $this->dir . '/error.log';
        ini_set('error_log', $log);
        $app = $this->app();
        BridgeRoutes::$routes['/signup'] = static function (ServerRequestInterface $r, Container $c): ResponseInterface {
            $c->get(Notifier::class)->sendLater(new Email('member@site.test', 'Welcome', 'Text'));

            return new Response(200, [], 'signed up');
        };

        $response = $app->handle(new ServerRequest('GET', 'https://site.test/signup'));

        $this->assertSame('signed up', (string) $response->getBody());
        $this->assertStringContainsString('Deferred notification failed', (string) @file_get_contents($log));
        $this->assertSame(0, Contexts::depth());
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** @return list<string> */
    private function sentTo(Application $app): array
    {
        return array_map(static fn (SentMessage $s): string => $s->message->to[0], $app->container()->get(TransportFactory::class)->get('mail')->sent());
    }
}

final class BridgeRoutes implements MiddlewareInterface
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
