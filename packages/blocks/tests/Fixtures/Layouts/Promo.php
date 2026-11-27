<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Layouts;

use Preflow\Blocks\Layouts\Area;
use Preflow\Blocks\Layouts\Layout;

/** A page layout with a limited hero and a main area that takes every block but banners. */
#[Layout(key: 'promo', label: 'Promo', template: 'layouts/promo.twig')]
final class Promo
{
    #[Area(label: 'Hero', allowed: ['banner'], max: 1)]
    public array $hero = [];

    #[Area(label: 'Main', except: ['banner'])]
    public array $main = [];
}
