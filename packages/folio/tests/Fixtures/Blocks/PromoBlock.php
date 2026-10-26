<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Folio\Block\Block;
use Preflow\Folio\Block\BlockField;

/** Data-only block whose texts are translation keys (lang/{locale}/site.php). */
#[Block(key: 'promo', label: 'site.blocks.promo.label', group: 'site.blocks.groups.marketing', description: 'site.blocks.promo.description')]
final class PromoBlock
{
    #[BlockField(type: 'string', label: 'site.blocks.promo.headline', help: 'site.blocks.promo.headline_help')]
    public string $headline = '';
}
