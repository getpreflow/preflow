<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** Data-only block: no template, its parent renders it. */
#[Block(key: 'link', label: 'Link', icon: 'link')]
final class LinkBlock
{
    #[BlockField(type: 'string', translatable: true)]
    public string $label = '';

    #[BlockField(type: 'string')]
    public string $url = '';
}
