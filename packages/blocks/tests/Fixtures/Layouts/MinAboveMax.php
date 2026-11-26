<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Layouts;

use Preflow\Blocks\Layouts\Area;
use Preflow\Blocks\Layouts\Layout;

#[Layout(key: 'minmax', label: 'Min max', template: 'layouts/minmax.twig')]
final class MinAboveMax
{
    #[Area(label: 'Main', min: 3, max: 1)]
    public array $main = [];
}
