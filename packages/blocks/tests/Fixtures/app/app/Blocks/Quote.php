<?php

declare(strict_types=1);

namespace App\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A data-only block: no component, its parent's template renders it. */
#[Block(key: 'quote', label: 'Quote')]
final class Quote
{
    #[BlockField(type: 'string', translatable: true)]
    public string $text = '';
}
