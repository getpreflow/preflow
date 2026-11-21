<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

use Psr\Clock\ClockInterface;

/**
 * A clock that always tells the same time (RenderContext::at(), tests).
 *
 * @since 0.81.0
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(private readonly \DateTimeImmutable $at) {}

    public function now(): \DateTimeImmutable
    {
        return $this->at;
    }
}
