<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Values;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/**
 * A data-only block with the core values a block may hold: rich text (translatable and not), a
 * number, a relation, a media field, a field shown only for one kind, and nested cards.
 */
#[Block(key: 'value_card', label: 'Card')]
final class Card
{
    #[BlockField(type: 'richtext', translatable: true)]
    public string $body = '';

    #[BlockField(type: 'richtext')]
    public string $note = '';

    #[BlockField(type: 'number')]
    public int $count = 0;

    #[BlockField(type: 'relation', config: ['relation' => ['to' => 'person']])]
    public string $author = '';

    #[BlockField(type: 'asset', config: ['asset' => ['multiple' => true]])]
    public array $images = [];

    #[BlockField(type: 'select', config: ['options' => ['plain' => 'Plain', 'link' => 'Link']])]
    public string $kind = 'plain';

    #[BlockField(type: 'string', config: ['show_when' => ['field' => 'kind', '=' => 'link']])]
    public string $url = '';

    #[BlockField(type: 'blocks', allowed: ['value_card'])]
    public array $children = [];
}
