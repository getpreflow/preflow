<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Registered in phpunit.xml: removes the temp roots a test made (TempRoots) as soon as it
 * finishes, and keeps the PHP session files the booted apps write in a folder of their own that
 * goes with them, instead of the temp directory.
 *
 * @since 0.84.0
 */
final class TempRootsExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        TempRoots::isolateSessions();

        $facade->registerSubscriber(new class implements FinishedSubscriber {
            public function notify(Finished $event): void
            {
                TempRoots::clean();
            }
        });
    }
}
