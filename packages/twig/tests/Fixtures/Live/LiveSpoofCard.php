<?php

declare(strict_types=1);

namespace Preflow\Twig\Tests\Fixtures\Live;

use Preflow\Components\Component;

/** A component whose own template has a live block: its {% embed %} passes a componentClass of its own. */
final class LiveSpoofCard extends Component
{
}
