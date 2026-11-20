<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\FrozenClock;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Psr\Clock\ClockInterface;

final class ClockTest extends TestCase
{
    public function test_frozen_clock_never_moves(): void
    {
        $at = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));
        $clock = new FrozenClock($at);

        $this->assertInstanceOf(ClockInterface::class, $clock);
        $first = $clock->now();
        usleep(1000);
        $this->assertEquals($at, $first);
        $this->assertEquals($first, $clock->now());
    }

    public function test_system_clock_tells_the_current_time(): void
    {
        $clock = new SystemClock();
        $before = new \DateTimeImmutable();
        $now = $clock->now();

        $this->assertInstanceOf(ClockInterface::class, $clock);
        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual(new \DateTimeImmutable(), $now);
    }

    /** Contexts::clock(): the current render's clock, the system clock outside every render. */
    public function test_contexts_clock_is_the_current_renders_clock(): void
    {
        Contexts::reset();
        $this->assertInstanceOf(SystemClock::class, Contexts::clock());

        $frozen = new FrozenClock(new \DateTimeImmutable('2026-03-01T00:00:00Z'));
        $seen = Contexts::run(RenderContext::console(new Site('http://localhost', ['en'], 'en'), $frozen), static fn (): ClockInterface => Contexts::clock());
        $this->assertSame($frozen, $seen);
        $at = Contexts::run(RenderContext::console(new Site('http://localhost', ['en'], 'en'), new SystemClock())->at(new \DateTimeImmutable('2020-01-01T00:00:00Z')), static fn (): string => Contexts::clock()->now()->format(DATE_ATOM));
        $this->assertSame('2020-01-01T00:00:00+00:00', $at);
        $this->assertInstanceOf(SystemClock::class, Contexts::clock());
    }
}
