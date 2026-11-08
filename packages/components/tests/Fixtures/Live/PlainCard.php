<?php

declare(strict_types=1);

namespace Preflow\Components\Tests\Fixtures\Live;

use Preflow\Components\Component;

/** A component that is not live. */
final class PlainCard extends Component
{
    public string $state = 'plain';
}
