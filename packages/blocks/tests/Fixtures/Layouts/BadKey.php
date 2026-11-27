<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Layouts;

use Preflow\Blocks\Layouts\Area;
use Preflow\Blocks\Layouts\Layout;

#[Layout(key: 'Bad Key', label: 'Bad', template: 'layouts/bad.twig')]
final class BadKey
{
    #[Area(label: 'Main')]
    public array $main = [];
}
