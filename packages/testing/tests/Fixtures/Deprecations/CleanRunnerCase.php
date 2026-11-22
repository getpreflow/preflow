<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fixtures\Deprecations;

use Preflow\Core\Context\RenderContext;

/**
 * A site runner whose page calls nothing deprecated: it passes under failOnDeprecation, although
 * the contract calls deprecated setters on purpose. Not part of any suite (no Test.php suffix).
 */
final class CleanRunnerCase extends DeprecationFixtureRunner
{
    protected function page(RenderContext $ctx): string
    {
        return self::describe($ctx);
    }
}
