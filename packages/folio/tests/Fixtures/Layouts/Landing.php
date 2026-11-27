<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Layouts;

use Preflow\Blocks\Layouts\Area;
use Preflow\Blocks\Layouts\Layout;

/** A page layout with one hero block on top and a main area below. */
#[Layout(key: 'landing', label: 'Landing page', template: 'layouts/landing.twig')]
final class Landing
{
    #[Area(label: 'Hero', allowed: ['hero'], max: 1)]
    public array $hero = [];

    #[Area(label: 'Main')]
    public array $main = [];
}
