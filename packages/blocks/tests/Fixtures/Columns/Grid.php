<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Columns;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A data-only block with an areas field over the set "grid" (GridLayouts), as the columns block has one. */
#[Block(key: 'grid', label: 'Grid')]
final class Grid
{
    #[BlockField(type: 'areas', label: 'Columns', config: ['layouts' => 'grid'])]
    public array $columns = [];
}
