<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

#[Block(key: 'options_from_cards', label: 'Choice')]
final class OptionsFromCards
{
    #[BlockField(type: 'tags')]
    public array $choices = [];

    #[BlockField(type: 'select', config: ['options_from' => 'choices', 'display' => 'cards'])]
    public string $choice = '';
}
