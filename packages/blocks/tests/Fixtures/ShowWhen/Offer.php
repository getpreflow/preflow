<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\ShowWhen;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;
use Preflow\Blocks\BlockField;

/** A component block whose button address shows only for a link; its lines are data-only blocks it prints. */
#[Block(key: 'offer', label: 'Offer')]
final class Offer extends BlockComponent
{
    #[BlockField(type: 'select', config: ['options' => ['text' => 'Text', 'link' => 'Link']])]
    public string $kind = 'text';

    #[BlockField(type: 'string', validate: ['required'], config: ['show_when' => ['field' => 'kind', '=' => 'link']])]
    public string $button_url = '';

    #[BlockField(type: 'blocks', allowed: ['offer_line'])]
    public array $lines = [];
}
