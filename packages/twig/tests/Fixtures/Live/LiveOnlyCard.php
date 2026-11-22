<?php

declare(strict_types=1);

namespace Preflow\Twig\Tests\Fixtures\Live;

use Preflow\Components\Component;

/** A component whose own template has a live block: its {% live %} inside an {% embed … only %}. */
final class LiveOnlyCard extends Component
{
}
