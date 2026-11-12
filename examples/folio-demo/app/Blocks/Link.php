<?php

declare(strict_types=1);

namespace Preflow\Examples\FolioDemo\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** Data-only block: the Hero template renders it. */
#[Block(key: 'link', label: 'Link', icon: 'link')]
final class Link
{
    #[BlockField(type: 'string', translatable: true, validate: ['required'])]
    public string $label = '';

    #[BlockField(type: 'string', validate: ['required'])]
    public string $url = '';
}
