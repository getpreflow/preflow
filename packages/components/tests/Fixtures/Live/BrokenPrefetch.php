<?php

declare(strict_types=1);

namespace Preflow\Components\Tests\Fixtures\Live;

use Preflow\Components\Component;
use Preflow\Components\Live;
use Preflow\Components\PrefetchesLive;

/** A live component whose prefetch fails. */
#[Live]
final class BrokenPrefetch extends Component implements PrefetchesLive
{
    public string $state = 'broken-prefetch';

    public function prefetchLive(array $propsList): void
    {
        throw new \RuntimeException('prefetch down');
    }
}
