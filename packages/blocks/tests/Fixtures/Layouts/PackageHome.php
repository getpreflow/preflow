<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Layouts;

use Preflow\Blocks\Layouts\Area;
use Preflow\Blocks\Layouts\Layout;

/** A layout an installed package lists in extra.preflow.layouts. */
#[Layout(key: 'package_home', label: 'Package home', template: 'layouts/package-home.twig')]
final class PackageHome
{
    #[Area(label: 'Top')]
    public array $top = [];
}
