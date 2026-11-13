<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;
use Preflow\Data\TypeFamily;

/** A block that declares its family. */
#[Block(key: 'gallery', label: 'Gallery', icon: 'block-image', family: TypeFamily::Asset)]
final class GalleryBlock
{
    #[BlockField(type: 'string')]
    public string $caption = '';
}
