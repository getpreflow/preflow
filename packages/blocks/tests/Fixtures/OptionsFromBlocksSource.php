<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

#[Block(key: 'options_from_blocks_source', label: 'Choice')]
final class OptionsFromBlocksSource
{
    #[BlockField(type: 'blocks', allowed: ['quote'])]
    public array $items = [];

    #[BlockField(type: 'select', config: ['options_from' => 'items'])]
    public string $choice = '';
}
