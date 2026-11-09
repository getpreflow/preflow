<?php

declare(strict_types=1);

namespace App\Components\Blocks\Hero;

use Preflow\Folio\Block\Block;
use Preflow\Folio\Block\BlockComponent;
use Preflow\Folio\Block\BlockField;

/** A page's opening: the headline (the page's h1), a short intro, an optional image and button. */
#[Block(key: 'hero', label: 'Hero', group: 'Sections', description: 'Headline, intro, image and a button')]
final class Hero extends BlockComponent
{
    protected string $tag = 'section';
    protected string $cssClass = 'hero';
    protected bool $scopeCss = true;

    #[BlockField(type: 'string', validate: ['required'])]
    public string $headline = '';

    #[BlockField(type: 'text')]
    public string $intro = '';

    #[BlockField(type: 'asset', label: 'Image', help: 'Optional.', config: ['asset' => ['multiple' => false, 'accept' => 'image/*']])]
    public string $image = '';

    #[BlockField(type: 'string', label: 'Button label')]
    public string $cta_label = '';

    #[BlockField(type: 'string', label: 'Button link', help: 'A path on this site, such as /jobs, or an https:// address.')]
    public string $cta_url = '';
}
