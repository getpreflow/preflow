<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks\HeroBlock;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;
use Preflow\Blocks\BlockField;
use Preflow\Data\TypeFamily;

#[Block(key: 'hero', label: 'Hero', group: 'Sections', icon: 'block', description: 'Big opening section', family: TypeFamily::Page)]
final class HeroBlock extends BlockComponent
{
    #[BlockField(type: 'string', translatable: true, validate: ['required'])]
    public string $headline = '';

    #[BlockField(type: 'text', translatable: true)]
    public string $intro = '';

    #[BlockField(type: 'blocks', allowed: ['tradeoff_note'], max: 1)]
    public array $tradeoff = [];
}
