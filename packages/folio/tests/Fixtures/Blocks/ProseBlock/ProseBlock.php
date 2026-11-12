<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks\ProseBlock;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;
use Preflow\Blocks\BlockField;

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
