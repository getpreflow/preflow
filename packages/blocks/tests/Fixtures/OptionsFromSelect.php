<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

#[Block(key: 'options_from_select', label: 'Choice')]
final class OptionsFromSelect
{
    #[BlockField(type: 'tags')]
    public array $choices = [];

    #[BlockField(type: 'select', config: ['options_from' => 'choices'])]
    public string $choice = '';
}
