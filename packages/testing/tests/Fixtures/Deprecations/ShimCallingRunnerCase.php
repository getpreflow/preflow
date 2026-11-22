<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fixtures\Deprecations;

use Preflow\Core\Context\RenderContext;

/**
 * A site runner whose page calls a deprecated shim of its own (legacyCall()): the run fails under
 * failOnDeprecation. Not part of any suite
 * (no Test.php suffix).
 */
final class ShimCallingRunnerCase extends DeprecationFixtureRunner
{
    protected function page(RenderContext $ctx): string
    {
        self::legacyCall();

        return self::describe($ctx);
    }
}
