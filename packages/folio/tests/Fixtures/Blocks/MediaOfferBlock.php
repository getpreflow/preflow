<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** Data-only block whose image field shows only for the "media" kind. */
#[Block(key: 'media_offer', label: 'Media offer')]
final class MediaOfferBlock
{
    #[BlockField(type: 'string')]
    public string $kind = 'text';

    #[BlockField(type: 'asset', config: ['show_when' => ['field' => 'kind', '=' => 'media']])]
    public array $image = [];
}
