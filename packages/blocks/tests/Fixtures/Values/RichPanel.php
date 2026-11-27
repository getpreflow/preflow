<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Values;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;
use Preflow\Blocks\BlockField;

/** A component block printing its rich text as it gets it (|raw), and its cards' notes. */
#[Block(key: 'rich_panel', label: 'Rich panel')]
final class RichPanel extends BlockComponent
{
    #[BlockField(type: 'richtext', translatable: true)]
    public string $body = '';

    #[BlockField(type: 'blocks', allowed: ['value_card'])]
    public array $cards = [];
}
