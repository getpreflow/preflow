<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests\Support;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A data-only block whose datetime field names a zone that does not exist. */
#[Block(key: 'bad_zone', label: 'Bad zone')]
final class BadZoneBlock
{
    #[BlockField(type: 'datetime', label: 'Starts', config: ['zone' => 'Europe/Zurch'])]
    public ?string $starts = null;
}
