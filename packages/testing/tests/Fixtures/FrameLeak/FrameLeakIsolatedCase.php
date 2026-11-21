<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fixtures\FrameLeak;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Testing\ChecksFrameBalance;
use Preflow\Testing\ContextFactory;

/**
 * Leaves a render frame open in a test that runs in a separate process, where no extension runs:
 * ChecksFrameBalance must fail the test. Not part of any suite (no Test.php suffix);
 * ContextHelpersTest runs it in a PHPUnit subprocess.
 */
final class FrameLeakIsolatedCase extends TestCase
{
    use ChecksFrameBalance;

    /** Kept alive, so the frame stays open after the test. */
    public static ?\Fiber $fiber = null;

    #[RunInSeparateProcess]
    public function test_leaves_a_frame_open_in_its_own_process(): void
    {
        self::$fiber = new \Fiber(static fn () => Contexts::run(ContextFactory::forTest(), static fn () => \Fiber::suspend()));
        self::$fiber->start();

        $this->assertSame(1, Contexts::depth());
    }
}
