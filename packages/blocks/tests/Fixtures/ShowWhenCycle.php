<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

#[Block(key: 'show_when_cycle', label: 'Show when')]
final class ShowWhenCycle
{
    #[BlockField(type: 'string', config: ['show_when' => ['field' => 'url', 'filled' => true]])]
    public string $kind = '';

    #[BlockField(type: 'string', config: ['show_when' => ['field' => 'kind', 'filled' => true]])]
    public string $url = '';
}
