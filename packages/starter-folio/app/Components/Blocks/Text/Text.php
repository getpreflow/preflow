<?php

declare(strict_types=1);

namespace App\Components\Blocks\Text;

use Preflow\Folio\Block\Block;
use Preflow\Folio\Block\BlockComponent;
use Preflow\Folio\Block\BlockField;

/** Formatted text: paragraphs, headings, lists and links. */
#[Block(key: 'text', label: 'Text', group: 'Sections')]
final class Text extends BlockComponent
{
    protected string $tag = 'section';
    protected string $cssClass = 'text-block';
    protected bool $scopeCss = true;

    #[BlockField(type: 'richtext')]
    public string $body = '';
}
