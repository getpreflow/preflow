<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks\HeroBlock;

use Preflow\Folio\Block\Block;
use Preflow\Folio\Block\BlockComponent;
use Preflow\Folio\Block\BlockField;

#[Block(key: 'hero', label: 'Hero', group: 'Sections', icon: 'block', description: 'Big opening section')]
final class HeroBlock extends BlockComponent
{
    #[BlockField(type: 'string', translatable: true, validate: ['required'])]
    public string $headline = '';

    #[BlockField(type: 'text', translatable: true)]
    public string $intro = '';

    #[BlockField(type: 'blocks', allowed: ['tradeoff_note'], max: 1)]
    public array $tradeoff = [];
}
