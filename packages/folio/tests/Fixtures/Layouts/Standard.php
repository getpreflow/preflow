<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Layouts;

use Preflow\Blocks\Layouts\Area;
use Preflow\Blocks\Layouts\Layout;

/** A page layout with a main area only. */
#[Layout(key: 'standard', label: 'Standard', template: 'layouts/standard.twig')]
final class Standard
{
    #[Area(label: 'Main')]
    public array $main = [];
}
