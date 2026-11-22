<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fixtures\Deprecations;

use Nyholm\Psr7\ServerRequest;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Http\InternalRender;

/**
 * A site runner whose page calls a deprecated shim (InternalRender::mark()): the run fails under
 * failOnDeprecation, the contract's own deprecated calls notwithstanding. Not part of any suite
 * (no Test.php suffix).
 */
final class ShimCallingRunnerCase extends DeprecationFixtureRunner
{
    protected function page(RenderContext $ctx): string
    {
        InternalRender::mark(new ServerRequest('GET', '/'));

        return self::describe($ctx);
    }
}
