<?php

declare(strict_types=1);

namespace App\Layouts;

use Preflow\Blocks\Layouts\Area;
use Preflow\Blocks\Layouts\Layout;

#[Layout(key: 'landing', label: 'Landing', template: 'layouts/landing.twig', icon: 'rocket')]
final class Landing
{
    /** @var list<array<string, mixed>> */
    #[Area(label: 'Hero', allowed: ['hero'], max: 1)]
    public array $hero = [];

    /** @var list<array<string, mixed>> */
    #[Area(label: 'Main', except: ['columns'])]
    public array $main = [];
}
