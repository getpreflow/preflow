<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks\GridBlock;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;
use Preflow\Blocks\BlockField;

/** A component block with an areas field over the set "grid" (GridSetProvider), as the columns block has one. */
#[Block(key: 'grid', label: 'Grid', group: 'Layout')]
final class GridBlock extends BlockComponent
{
    #[BlockField(type: 'areas', label: 'Columns', config: ['layouts' => 'grid'])]
    public array $columns = [];
}
