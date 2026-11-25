<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

#[Block(key: 'options_from_itself', label: 'Choice')]
final class OptionsFromItself
{
    #[BlockField(type: 'tags')]
    public array $choices = [];

    #[BlockField(type: 'select', config: ['options_from' => 'choice'])]
    public string $choice = '';
}
