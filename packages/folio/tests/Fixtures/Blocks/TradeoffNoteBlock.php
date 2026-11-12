<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

#[Block(key: 'tradeoff_note', label: 'Tradeoff note')]
final class TradeoffNoteBlock
{
    #[BlockField(type: 'string', translatable: true)]
    public string $chose = '';

    #[BlockField(type: 'asset')]
    public string $cover = '';
}
