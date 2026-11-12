<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A block listed in config/blocks.php 'classes'. */
#[Block(key: 'config_card', label: 'Card')]
final class ConfigCard
{
    #[BlockField(type: 'string', label: 'Heading', validate: ['required'])]
    public string $heading = '';

    #[BlockField(type: 'select', config: ['options' => ['a' => 'A']])]
    public string $style = 'a';
}
