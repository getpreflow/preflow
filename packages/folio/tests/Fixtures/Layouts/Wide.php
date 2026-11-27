<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Layouts;

use Preflow\Blocks\Layouts\Area;
use Preflow\Blocks\Layouts\Layout;

/** A page layout whose one area takes every block but the hero ("except"). */
#[Layout(key: 'wide', label: 'Wide', template: 'layouts/standard.twig')]
final class Wide
{
    #[Area(label: 'Main', except: ['hero'])]
    public array $main = [];
}
