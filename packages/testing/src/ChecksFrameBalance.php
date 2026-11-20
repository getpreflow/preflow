<?php

declare(strict_types=1);

namespace Preflow\Testing;

use PHPUnit\Framework\Attributes\After;
use Preflow\Core\Context\Contexts;

/**
 * Fails the test when it left a render frame open (Contexts::depth() is not 0 after it), in the
 * test's own process: use it in every test class that runs tests in separate processes
 * (#[RunInSeparateProcess], #[RunTestsInSeparateProcesses]), where FrameBalanceExtension never
 * runs (PHPUnit starts no extension in a child process). The frames are closed first, so the next
 * test starts clean.
 *
 *     final class PageTest extends TestCase
 *     {
 *         use ChecksFrameBalance;
 *     }
 *
 * @since 0.81.0
 */
trait ChecksFrameBalance
{
    #[After]
    protected function assertNoRenderFrameLeftOpen(): void
    {
        // Without core loaded no frame was ever opened (a package's test that runs without core).
        if (!class_exists(Contexts::class, false)) {
            return;
        }
        $depth = Contexts::depth();
        if ($depth === 0) {
            return;
        }
        Contexts::reset();
        try {
            FrameBalanceExtension::assertBalanced(static::class . '::' . $this->name(), $depth);
        } catch (\LogicException $e) {
            $this->fail($e->getMessage());
        }
    }
}
