<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** Data-only block with a multiple asset field. */
#[Block(key: 'gallery', label: 'Gallery')]
final class GalleryBlock
{
    /** @var list<string> */
    #[BlockField(type: 'asset', config: ['asset' => ['multiple' => true]])]
    public array $photos = [];
}
