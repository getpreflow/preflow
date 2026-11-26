<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** Data-only block with a conditional field: the button address shows (and is required) only for a link. */
#[Block(key: 'offer', label: 'Offer')]
final class OfferBlock
{
    #[BlockField(type: 'select', config: ['options' => ['text' => 'Text', 'link' => 'Link']])]
    public string $kind = 'text';

    #[BlockField(type: 'string', label: 'Button address', validate: ['required'], config: ['show_when' => ['field' => 'kind', '=' => 'link']])]
    public string $button_url = '';

    #[BlockField(type: 'string', label: 'Button text', translatable: true, config: ['show_when' => ['field' => 'kind', '=' => 'link']])]
    public string $button_label = '';
}
