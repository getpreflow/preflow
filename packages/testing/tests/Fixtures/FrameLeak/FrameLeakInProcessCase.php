<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fixtures\FrameLeak;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Testing\ContextFactory;

/**
 * Leaves a render frame open (a Fiber suspended inside Contexts::run()), in the runner's own
 * process: FrameBalanceExtension must fail the run. Not part of any suite (no Test.php suffix);
 * ContextHelpersTest runs it in a PHPUnit subprocess.
 */
final class FrameLeakInProcessCase extends TestCase
{
    /** Kept alive, so the frame stays open after the test. */
    public static ?\Fiber $fiber = null;

    public function test_leaves_a_frame_open(): void
    {
        self::$fiber = new \Fiber(static fn () => Contexts::run(ContextFactory::forTest(), static fn () => \Fiber::suspend()));
        self::$fiber->start();

        $this->assertSame(1, Contexts::depth());
    }
}
