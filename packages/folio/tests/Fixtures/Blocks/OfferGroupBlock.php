<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A block whose nested offers show only in the "list" layout. */
#[Block(key: 'offer_group', label: 'Offer group')]
final class OfferGroupBlock
{
    #[BlockField(type: 'select', config: ['options' => ['none' => 'None', 'list' => 'List']])]
    public string $layout = 'list';

    #[BlockField(type: 'blocks', allowed: ['offer'], min: 1, config: ['show_when' => ['field' => 'layout', '=' => 'list']])]
    public array $offers = [];
}
