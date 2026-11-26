<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\ShowWhen;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A data-only block: its note shows only on a featured line. */
#[Block(key: 'offer_line', label: 'Offer line')]
final class OfferLine
{
    #[BlockField(type: 'boolean')]
    public bool $featured = false;

    #[BlockField(type: 'string', config: ['show_when' => ['field' => 'featured', '=' => true]])]
    public string $note = '';
}
