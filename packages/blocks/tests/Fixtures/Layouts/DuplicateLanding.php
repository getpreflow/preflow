<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Layouts;

use Preflow\Blocks\Layouts\Area;
use Preflow\Blocks\Layouts\Layout;

#[Layout(key: 'landing', label: 'Other landing', template: 'layouts/other.twig')]
final class DuplicateLanding
{
    #[Area(label: 'Main')]
    public array $main = [];
}
