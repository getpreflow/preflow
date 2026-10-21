<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks\ProseBlock;

use Preflow\Folio\Block\Block;
use Preflow\Folio\Block\BlockComponent;
use Preflow\Folio\Block\BlockField;

#[Block(key: 'prose', label: 'Prose', group: 'Content')]
final class ProseBlock extends BlockComponent
{
    #[BlockField(type: 'richtext', translatable: true)]
    public string $body = '';

    #[BlockField(type: 'string', translatable: true)]
    public string $caption = '';

    #[BlockField(type: 'blocks', allowed: ['quote'])]
    public array $quotes = [];
}
