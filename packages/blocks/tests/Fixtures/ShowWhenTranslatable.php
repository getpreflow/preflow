<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

#[Block(key: 'show_when_translatable', label: 'Show when')]
final class ShowWhenTranslatable
{
    #[BlockField(type: 'string', translatable: true)]
    public string $kind = '';

    #[BlockField(type: 'string', config: ['show_when' => ['field' => 'kind', '=' => 'link']])]
    public string $url = '';
}
