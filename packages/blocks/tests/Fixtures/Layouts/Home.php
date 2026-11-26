<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Layouts;

use Preflow\Blocks\Layouts\Area;
use Preflow\Blocks\Layouts\Layout;

/** A layout only the pinned home page uses (not in the type's "allowed"). */
#[Layout(key: 'home', label: 'Home', template: 'layouts/home.twig')]
final class Home
{
    #[Area(label: 'Top', min: 1)]
    public array $top = [];
}
