<?php

declare(strict_types=1);

namespace Preflow\Twig\Tests\Fixtures\Live;

use Preflow\Components\Component;

/** A component whose own template has a live block: it renders another component before its own {% live %}. */
final class LiveOuter extends Component
{
}
