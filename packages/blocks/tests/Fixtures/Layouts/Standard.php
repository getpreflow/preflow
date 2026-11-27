<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Layouts;

use Preflow\Blocks\Layouts\Area;
use Preflow\Blocks\Layouts\Layout;

/** Listed in config/blocks.php 'layouts' (or a package). */
#[Layout(key: 'standard', label: 'Standard', template: 'layouts/standard.twig')]
final class Standard
{
    #[Area(label: 'Main')]
    public array $main = [];
}
