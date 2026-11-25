<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

#[Block(key: 'options_from_not_select', label: 'Choice')]
final class OptionsFromNotSelect
{
    #[BlockField(type: 'tags')]
    public array $choices = [];

    #[BlockField(type: 'string', config: ['options_from' => 'choices'])]
    public string $choice = '';
}
