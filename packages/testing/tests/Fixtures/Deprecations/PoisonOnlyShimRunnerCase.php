<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fixtures\Deprecations;

use Preflow\Core\Application;
use Preflow\Core\Context\RenderContext;

/**
 * A site runner whose deprecated call (legacyCall()) is reached only under the poison,
 * in its poisonApp(): the poison and frame tests report it, the run fails. Not part of any suite
 * (no Test.php suffix).
 */
final class PoisonOnlyShimRunnerCase extends DeprecationFixtureRunner
{
    protected function page(RenderContext $ctx): string
    {
        return self::describe($ctx);
    }

    protected function poisonApp(Application $app): void
    {
        self::legacyCall();
    }
}
