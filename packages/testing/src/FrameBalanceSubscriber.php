<?php

declare(strict_types=1);

namespace Preflow\Testing;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use Preflow\Core\Context\Contexts;

/**
 * FrameBalanceExtension's check after each test: the frames a test left open are closed, then
 * the test is reported.
 *
 * @since 0.81.0
 * @internal FrameBalanceExtension
 */
final class FrameBalanceSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        $depth = Contexts::depth();
        if ($depth !== 0) {
            Contexts::reset();
        }
        FrameBalanceExtension::assertBalanced($event->test()->id(), $depth);
    }
}
