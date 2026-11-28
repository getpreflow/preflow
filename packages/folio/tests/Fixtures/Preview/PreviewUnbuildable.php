<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Preview;

use Preflow\Components\Component;

/** A type preview's component the container cannot build: its constructor needs an interface nothing binds. */
final class PreviewUnbuildable extends Component
{
    public function __construct(public readonly \Countable $unbound)
    {
    }
}
