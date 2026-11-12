<?php

declare(strict_types=1);

namespace App\Components\Banner;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;
use Preflow\Blocks\BlockField;

/** A component block: its template renders its quotes (data-only) and its children (blocks()). */
#[Block(key: 'banner', label: 'Banner', group: 'Sections', icon: 'flag')]
final class Banner extends BlockComponent
{
    #[BlockField(type: 'string', translatable: true)]
    public string $title = '';

    #[BlockField(type: 'text')]
    public string $body = '';

    #[BlockField(type: 'blocks', allowed: ['quote'])]
    public array $quotes = [];

    #[BlockField(type: 'blocks', allowed: ['banner'])]
    public array $children = [];
}
