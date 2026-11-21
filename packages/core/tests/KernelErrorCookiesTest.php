<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Error\ErrorRendererInterface;
use Preflow\Core\Http\CookieQueue;
use Preflow\Core\Http\MiddlewarePipeline;
use Preflow\Core\Kernel;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouteMode;
use Preflow\Core\Routing\RouterInterface;
use Preflow\Testing\ChecksFrameBalance;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Kernel's error path with a frame-bound cookie queue and the frames' rule
 * (CookieQueue::reachesResponse(), since 0.82.0): what a request queued before it failed, and
 * what its error page queued while it rendered, follow the frame's outbox policy, each set once.
 */
final class KernelErrorCookiesTest extends TestCase
{
    use ChecksFrameBalance;

    protected function setUp(): void
    {
        Contexts::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
    }

    public function test_a_failing_request_sets_what_it_and_its_error_page_queued_once(): void
    {
        $queue = CookieQueue::ofCurrentFrame();
        $kernel = $this->kernel($queue);

        $response = Contexts::run(self::ctx(), static fn (): ResponseInterface => $kernel->handle(new ServerRequest('GET', 'https://site.test/boom')));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['route=1; Path=/', 'error-page=1; Path=/'], $response->getHeader('Set-Cookie'));
    }

    public function test_a_failing_render_leaves_what_it_and_its_error_page_queued_to_its_opener(): void
    {
        $queue = CookieQueue::ofCurrentFrame();
        $kernel = $this->kernel($queue);

        [$response, $left] = Contexts::runRender(self::ctx()->capturing(), static fn (): array => [
            $kernel->handle(new ServerRequest('GET', 'https://site.test/boom')),
            $queue->pull(),
        ]);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([], $response->getHeader('Set-Cookie'), 'a render\'s response is its opener\'s result: it never sets them');
        $this->assertSame(['route=1; Path=/', 'error-page=1; Path=/'], $left, 'its opener settles them by the frame\'s outbox policy');
    }

    public function test_a_failing_request_whose_frame_does_not_deliver_sets_none(): void
    {
        $queue = CookieQueue::ofCurrentFrame();
        $kernel = $this->kernel($queue);

        $response = Contexts::run(self::ctx()->discarding(), static fn (): ResponseInterface => $kernel->handle(new ServerRequest('GET', 'https://site.test/boom')));

        $this->assertSame([], $response->getHeader('Set-Cookie'), 'as its answer would not');
    }

    /** With a queue of its own (`new`) and no rule, a Kernel sets them on the error response, as before 0.82. */
    public function test_a_kernel_with_a_queue_of_its_own_sets_them_on_the_error_response_as_before(): void
    {
        $queue = new CookieQueue();
        $kernel = $this->kernel($queue, rule: false);

        $response = Contexts::runRender(self::ctx(), static fn (): ResponseInterface => $kernel->handle(new ServerRequest('GET', 'https://site.test/boom')));

        $this->assertSame(['route=1; Path=/', 'error-page=1; Path=/'], $response->getHeader('Set-Cookie'));
    }

    /** With a frame-bound queue a Kernel follows the frames' rule without being given it (since 0.82.0). */
    public function test_a_kernel_with_a_frame_bound_queue_follows_the_rule_by_default(): void
    {
        $queue = CookieQueue::ofCurrentFrame();
        $kernel = $this->kernel($queue, rule: false);

        [$rendered, $left] = Contexts::runRender(self::ctx(), static fn (): array => [$kernel->handle(new ServerRequest('GET', 'https://site.test/boom')), $queue->pull()]);
        $visited = Contexts::run(self::ctx(), static fn (): ResponseInterface => $kernel->handle(new ServerRequest('GET', 'https://site.test/boom')));

        $this->assertSame([], $rendered->getHeader('Set-Cookie'));
        $this->assertSame(['route=1; Path=/', 'error-page=1; Path=/'], $left);
        $this->assertSame(['route=1; Path=/', 'error-page=1; Path=/'], $visited->getHeader('Set-Cookie'));
    }

    /** A Kernel whose route queues a cookie and throws, and whose error page queues one while it renders. */
    private function kernel(CookieQueue $queue, bool $rule = true): Kernel
    {
        $router = new class implements RouterInterface {
            public function match(ServerRequestInterface $request): Route
            {
                return new Route(RouteMode::Action, 'boom');
            }
        };
        $renderer = new class ($queue) implements ErrorRendererInterface {
            public function __construct(private readonly CookieQueue $queue) {}

            public function render(\Throwable $exception, ServerRequestInterface $request, int $statusCode): string
            {
                $this->queue->queue('error-page=1; Path=/');

                return 'error';
            }
        };

        return new Kernel(
            container: new Container(),
            router: $router,
            pipeline: new MiddlewarePipeline(),
            errorHandler: new ErrorHandler($renderer),
            actionDispatcher: static function () use ($queue): ResponseInterface {
                $queue->queue('route=1; Path=/');
                throw new \RuntimeException('render failed');
            },
            componentRenderer: static fn (): ResponseInterface => new Response(500),
            cookies: $queue,
            setsQueuedCookies: $rule ? $queue->reachesResponse(...) : null,
        );
    }

    private static function ctx(): RenderContext
    {
        return RenderContext::fromRequest(new ServerRequest('GET', 'https://site.test/boom'), new Site('https://site.test', ['en'], 'en'), new SystemClock());
    }
}
