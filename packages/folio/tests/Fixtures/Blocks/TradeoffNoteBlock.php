<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Folio\Block\Block;
use Preflow\Folio\Block\BlockField;

#[Block(key: 'tradeoff_note', label: 'Tradeoff note')]
final class TradeoffNoteBlock
{
    #[BlockField(type: 'string', translatable: true)]
    public string $chose = '';

    #[BlockField(type: 'asset')]
    public string $cover = '';
}
