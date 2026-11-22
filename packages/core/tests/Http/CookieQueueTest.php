<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Http\CookieQueue;

final class CookieQueueTest extends TestCase
{
    protected function setUp(): void
    {
        Contexts::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
    }

    /** A queue of its own (`new`) is set by whoever holds it on the response it answers, in every frame: it never waits for a frame. */
    public function test_a_queue_of_its_own_always_reaches_the_response(): void
    {
        $plain = new CookieQueue();
        $bound = CookieQueue::ofCurrentFrame();

        $this->assertFalse($plain->isFrameBound());
        $this->assertTrue($bound->isFrameBound());
        $this->assertSame([true, true], [$plain->reachesResponse(), $bound->reachesResponse()], 'outside every frame');
        $this->assertSame([true, false], Contexts::runRender(self::ctx(), static fn (): array => [$plain->reachesResponse(), $bound->reachesResponse()]));
        $this->assertSame([true, false], Contexts::run(self::ctx()->capturing(), static fn (): array => [$plain->reachesResponse(), $bound->reachesResponse()]));
        $this->assertSame([true, true], Contexts::run(self::ctx(), static fn (): array => [$plain->reachesResponse(), $bound->reachesResponse()]));
    }

    /** What a delivering frame left queued goes to the queue of the frame around it when it closes. */
    public function test_a_delivering_frame_hands_what_it_left_to_the_frame_around_it(): void
    {
        $queue = CookieQueue::ofCurrentFrame();

        $left = Contexts::run(self::ctx(), static function () use ($queue): array {
            $queue->queue('outer=1');
            Contexts::run(self::ctx(), static fn () => $queue->queue('inner=1'));
            Contexts::runRender(self::ctx(), static fn () => $queue->queue('render=1'));

            return $queue->pull();
        });

        $this->assertSame(['outer=1', 'inner=1', 'render=1'], $left);
    }

    /** A capturing frame keeps what it left as captured 'cookies' items; a discarding one drops it; neither reaches the frame around it. */
    public function test_a_capturing_frame_captures_and_a_discarding_one_drops_what_it_left(): void
    {
        $queue = CookieQueue::ofCurrentFrame();

        [$captured, $dropped, $left] = Contexts::run(self::ctx(), static function () use ($queue): array {
            $frame = null;
            Contexts::run(self::ctx()->capturing(), static function () use ($queue, &$frame): void {
                $frame = Contexts::frame();
                $queue->queue('captured=1; Path=/');
            });
            $captured = array_map(static fn ($item): string => $item->channel . ' ' . $item->summary, $frame->madeOutbox()?->drain() ?? []);
            Contexts::run(self::ctx()->discarding(), static function () use ($queue, &$frame): void {
                $frame = Contexts::frame();
                $queue->queue('dropped=1');
            });

            return [$captured, $frame->madeOutbox(), $queue->pull()];
        });

        $this->assertSame(["cookies Cookie 'captured'"], $captured);
        $this->assertNull($dropped, 'a discarding frame makes no outbox for them');
        $this->assertSame([], $left);
    }

    /** A capturing request that is part of the frame around it (Contexts::part()) captures there: that frame reports it. */
    public function test_a_capturing_part_captures_in_the_frame_it_is_part_of(): void
    {
        $queue = CookieQueue::ofCurrentFrame();
        $outer = null;

        Contexts::run(self::ctx()->capturing(), static function () use ($queue, &$outer): void {
            $outer = Contexts::frame();
            Contexts::part(self::ctx()->capturing(), static fn () => $queue->queue('part=1; Path=/'));
        });

        $this->assertSame(['cookies'], array_map(static fn ($item): string => $item->channel, $outer->madeOutbox()?->drain() ?? []));
    }

    /** A capturing part inside a frame that delivers keeps what it captured: it would be dead weight there, never sent nor reported. */
    public function test_a_capturing_part_in_a_delivering_frame_keeps_its_captures(): void
    {
        $queue = CookieQueue::ofCurrentFrame();

        [$part, $outer] = Contexts::run(self::ctx(), static function () use ($queue): array {
            $part = null;
            Contexts::part(self::ctx()->capturing(), static function () use ($queue, &$part): void {
                $part = Contexts::frame();
                $queue->queue('part=1; Path=/');
            });

            return [$part, Contexts::frame()->madeOutbox()];
        });

        $this->assertNull($outer, 'nothing moved into the delivering frame');
        $this->assertSame(['cookies'], array_map(static fn ($item): string => $item->channel, $part->madeOutbox()?->drain() ?? []));
    }

    /** A frame that queued no cookie makes no outbox when it closes; one that throws hands nothing on. */
    public function test_closing_a_frame_without_cookies_costs_nothing_and_a_failed_one_leaves_nothing(): void
    {
        $queue = CookieQueue::ofCurrentFrame();

        $seen = Contexts::run(self::ctx(), static function () use ($queue): array {
            $frame = null;
            Contexts::run(self::ctx()->capturing(), static function () use (&$frame, $queue): void {
                $frame = Contexts::frame();
                $queue->pending();
            });
            try {
                Contexts::run(self::ctx(), static function () use ($queue): void {
                    $queue->queue('failed=1');
                    throw new \RuntimeException('boom');
                });
            } catch (\RuntimeException) {
            }

            return [$frame->madeOutbox(), Contexts::frame()->madeOutbox(), $queue->pull()];
        });

        $this->assertSame([null, null, []], $seen);
    }

    public function test_has_names_a_queued_cookie_until_it_is_applied(): void
    {
        $queue = new CookieQueue();
        $queue->queue('preflow_auth=1; Path=/');

        $this->assertTrue($queue->has('preflow_auth'));
        $this->assertFalse($queue->has('preflow'), 'the whole name');
        $this->assertFalse($queue->has('remember_session'));

        $response = $queue->apply(new Response());
        $this->assertSame(['preflow_auth=1; Path=/'], $response->getHeader('Set-Cookie'));
        $this->assertFalse($queue->has('preflow_auth'));
    }

    private static function ctx(): RenderContext
    {
        return RenderContext::fromRequest(new ServerRequest('GET', 'https://site.test/'), new Site('https://site.test', ['en'], 'en'), new SystemClock());
    }
}
