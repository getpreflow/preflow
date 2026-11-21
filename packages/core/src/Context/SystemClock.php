<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

use Psr\Clock\ClockInterface;

/**
 * The real time, in PHP's default timezone.
 *
 * @since 0.81.0
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
