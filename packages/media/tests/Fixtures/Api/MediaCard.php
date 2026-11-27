<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Fixtures\Api;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A data-only block with an image and a gallery. */
#[Block(key: 'media_card', label: 'Card')]
final class MediaCard
{
    #[BlockField(type: 'asset')]
    public string $image = '';

    #[BlockField(type: 'asset', config: ['asset' => ['multiple' => true]])]
    public array $gallery = [];
}
