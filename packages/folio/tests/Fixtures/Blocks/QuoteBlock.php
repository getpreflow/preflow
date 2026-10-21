<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Folio\Block\Block;
use Preflow\Folio\Block\BlockField;

/** Data-only: its parent's template renders it. */
#[Block(key: 'quote', label: 'Quote')]
final class QuoteBlock
{
    #[BlockField(type: 'richtext', translatable: true)]
    public string $text = '';
}
