<?php

declare(strict_types=1);

namespace Preflow\Testing;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Checks after every test that no render frame was left open: Contexts::depth() is back to 0
 * (every handle(), render() and Contexts::run() closed the frame it opened, also when it threw).
 * A test that leaves one open makes the run fail with a PHPUnit warning that names the test; the
 * frames are closed then (Contexts::reset()), so the next test starts clean.
 *
 * It sees in-process tests only: PHPUnit starts no extension in the child process of a test that
 * runs in a separate process, and in the parent the depth is always 0. Test classes with
 * #[RunInSeparateProcess] or #[RunTestsInSeparateProcesses] use the trait ChecksFrameBalance,
 * which fails the test in its own process (the framework's Tools suite requires it).
 *
 * Opt in from phpunit.xml (the framework's own suites do):
 *
 *     <extensions>
 *         <bootstrap class="Preflow\Testing\FrameBalanceExtension"/>
 *     </extensions>
 *
 * @since 0.81.0
 */
final class FrameBalanceExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new FrameBalanceSubscriber());
    }

    /**
     * @param string $test the test that just finished
     * @param int $depth Contexts::depth() after it
     *
     * @throws \LogicException when $depth is not 0
     */
    public static function assertBalanced(string $test, int $depth): void
    {
        if ($depth !== 0) {
            throw new \LogicException(sprintf(
                '%s left %d render frame%s open: Contexts::depth() is %d after the test, not 0. Every handle(), render() and Contexts::run() must close the frame it opened, also when it throws.',
                $test,
                $depth,
                $depth === 1 ? '' : 's',
                $depth,
            ));
        }
    }
}
