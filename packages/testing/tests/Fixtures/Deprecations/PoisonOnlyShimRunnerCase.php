<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fixtures\Deprecations;

use Nyholm\Psr7\ServerRequest;
use Preflow\Core\Application;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Http\InternalRender;

/**
 * A site runner whose deprecated call (InternalRender::mark()) is reached only under the poison,
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
        InternalRender::mark(new ServerRequest('GET', '/'));
    }
}
