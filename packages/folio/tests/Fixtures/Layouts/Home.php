<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Layouts;

use Preflow\Blocks\Layouts\Area;
use Preflow\Blocks\Layouts\Layout;

/** The layout the pinned home page uses (not in the type's "allowed"). */
#[Layout(key: 'home', label: 'Home', template: 'layouts/home.twig')]
final class Home
{
    #[Area(label: 'Top')]
    public array $top = [];
}
