<?php

declare(strict_types=1);

namespace Preflow\Twig\Tests\Fixtures\Live;

use Preflow\Components\Component;

/** A component whose own template has a {% live %} block inside an {% embed %}. */
final class LiveCard extends Component
{
}
