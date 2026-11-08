<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache\Fixtures;

use Preflow\Components\Component;

/** A component whose external feed is down: it throws while resolving, until FEED_UP is set. */
final class BrokenFeed extends Component
{
    public string $state = '';

    public function resolveState(): void
    {
        if (getenv('FEED_UP') !== '1') {
            throw new \RuntimeException('feed down');
        }
        $this->state = 'feed items';
    }
}
