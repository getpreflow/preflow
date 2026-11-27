<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Layouts;

use Preflow\Blocks\Layouts\Area;
use Preflow\Blocks\Layouts\Layout;

#[Layout(key: 'reserved', label: 'Reserved', template: 'layouts/reserved.twig')]
final class ReservedArea
{
    #[Area(label: 'Hidden')]
    public array $_meta = [];
}
