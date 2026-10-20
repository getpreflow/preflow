<?php

declare(strict_types=1);

namespace Preflow\Examples\FolioDemo\Blocks\Hero;

use Preflow\Folio\Block\Block;
use Preflow\Folio\Block\BlockComponent;
use Preflow\Folio\Block\BlockField;

/** Opening section: headline, intro and a few links (data-only "link" blocks). */
#[Block(key: 'hero', label: 'Hero', group: 'Sections', icon: 'block', description: 'Big opening section with links')]
final class Hero extends BlockComponent
{
    protected string $tag = 'section';

    protected string $cssClass = 'demo-hero';

    #[BlockField(type: 'string', translatable: true, validate: ['required'])]
    public string $headline = '';

    #[BlockField(type: 'text', translatable: true)]
    public string $intro = '';

    #[BlockField(type: 'asset', label: 'Image', help: 'From the asset library; set its focal point there.', config: ['asset' => ['accept' => 'image/*']])]
    public string $image = '';

    #[BlockField(type: 'blocks', label: 'Links', allowed: ['link'], max: 3)]
    public array $links = [];
}
