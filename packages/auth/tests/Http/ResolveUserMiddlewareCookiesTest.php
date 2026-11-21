<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\AuthManager;
use Preflow\Auth\ConfigUserProvider;
use Preflow\Auth\Http\ResolveUserMiddleware;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Auth\SessionGuard;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Http\CookieQueue;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Testing\ArraySession;
use Preflow\Testing\ContextFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Since 0.81.1 ResolveUserMiddleware drops no cookie: with a session it sets what the frame's
 * queue holds once the handler returned (also what was queued before it ran), without one it
 * leaves the queue to core (the Kernel), which sets it.
 */
final class ResolveUserMiddlewareCookiesTest extends TestCase
{
    protected function setUp(): void
    {
        Contexts::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
    }

    public function test_without_a_session_the_queue_is_left_to_core(): void
    {
        $queue = CookieQueue::ofCurrentFrame();
        $resolve = self::middleware($queue);

        [$response, $left] = Contexts::run(ContextFactory::forTest(), static function () use ($resolve, $queue): array {
            $response = $resolve->process(new ServerRequest('GET', '/about'), self::handler(static fn () => $queue->queue('consent=1; Path=/')));

            return [$response, $queue->pull()];
        });

        $this->assertSame([], $response->getHeader('Set-Cookie'), 'not this middleware\'s to set');
        $this->assertSame(['consent=1; Path=/'], $left, 'still queued for core: never dropped');
    }

    public function test_with_a_session_every_queued_cookie_is_set_once(): void
    {
        $queue = CookieQueue::ofCurrentFrame();
        $resolve = self::middleware($queue);
        $session = new ArraySession();
        $session->start();
        $request = (new ServerRequest('GET', '/about'))->withAttribute(SessionInterface::class, $session);

        [$response, $left] = Contexts::run(ContextFactory::forTest(), static function () use ($resolve, $queue, $request): array {
            $queue->queue('early=1; Path=/');
            $response = $resolve->process($request, self::handler(static fn () => $queue->queue('late=1; Path=/')));

            return [$response, $queue->pull()];
        });

        $this->assertSame(['early=1; Path=/', 'late=1; Path=/'], $response->getHeader('Set-Cookie'), 'a cookie queued before the middleware ran is set too');
        $this->assertSame([], $left, 'nothing left for core to set again');
    }

    /**
     * Since 0.82.0, in a frame whose response does not take its cookies (a render entry's frame,
     * a preview), it leaves them queued: the frame settles them by its policy when it closes.
     */
    public function test_in_a_render_frame_under_a_preview_it_leaves_the_queue_to_the_frame(): void
    {
        $queue = CookieQueue::ofCurrentFrame();
        $resolve = self::middleware($queue);
        $session = new ArraySession();
        $session->start();
        $request = (new ServerRequest('GET', '/about'))->withAttribute(SessionInterface::class, $session);

        foreach (['preview' => ContextFactory::forTest()->capturing(), 'delivering render' => ContextFactory::forTest()] as $name => $ctx) {
            [$response, $left] = Contexts::runRender($ctx, static function () use ($resolve, $queue, $request): array {
                $queue->queue('early=1; Path=/');
                $response = $resolve->process($request, self::handler(static fn () => $queue->queue('late=1; Path=/')));

                return [$response, $queue->pull()];
            });

            $this->assertSame([], $response->getHeader('Set-Cookie'), $name . ': a render\'s response is its caller\'s result');
            $this->assertSame(['early=1; Path=/', 'late=1; Path=/'], $left, $name . ': left to the frame');
        }
    }

    private static function middleware(CookieQueue $queue): ResolveUserMiddleware
    {
        $guard = new SessionGuard(new ConfigUserProvider([]), new NativePasswordHasher(), cookies: $queue);

        return new ResolveUserMiddleware(new AuthManager(['session' => $guard], 'session'), ['session' => $guard]);
    }

    /** @param \Closure(): void $then */
    private static function handler(\Closure $then): RequestHandlerInterface
    {
        return new class ($then) implements RequestHandlerInterface {
            public function __construct(private readonly \Closure $then) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->then)();

                return new Response(200);
            }
        };
    }
}
