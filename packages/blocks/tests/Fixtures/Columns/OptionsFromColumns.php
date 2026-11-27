<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Columns;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A select taking its options from an areas field: refused, the columns hold blocks, not values. */
#[Block(key: 'picky_grid', label: 'Picky grid')]
final class OptionsFromColumns
{
    #[BlockField(type: 'areas', config: ['layouts' => 'grid'])]
    public array $columns = [];

    #[BlockField(type: 'select', config: ['options_from' => 'columns'])]
    public string $pick = '';
}
