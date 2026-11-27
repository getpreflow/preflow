<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Layouts;

use Preflow\Blocks\Layouts\Layout;

#[Layout(key: 'empty', label: 'Empty', template: 'layouts/empty.twig')]
final class NoAreas
{
    public array $main = [];
}
