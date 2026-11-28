<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Preview;

use Preflow\Components\Component;

/** A preview view's component the container cannot build: its constructor needs an interface nothing binds. */
final class PreviewNeedsService extends Component
{
    public function __construct(public readonly \Countable $unbound)
    {
    }
}
