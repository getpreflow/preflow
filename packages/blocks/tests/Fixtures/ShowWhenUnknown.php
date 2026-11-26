<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

#[Block(key: 'show_when_unknown', label: 'Show when')]
final class ShowWhenUnknown
{
    #[BlockField(type: 'string', translatable: false)]
    public string $kind = '';

    #[BlockField(type: 'string', config: ['show_when' => ['field' => 'style', '=' => 'link']])]
    public string $url = '';
}
