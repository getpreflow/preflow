<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\FrozenClock;
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
}
