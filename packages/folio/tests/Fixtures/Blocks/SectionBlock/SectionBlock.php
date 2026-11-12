<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks\SectionBlock;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;
use Preflow\Blocks\BlockField;

/** A container block whose template renders its children without passing the preview flag. */
#[Block(key: 'section', label: 'Section', group: 'Sections')]
final class SectionBlock extends BlockComponent
{
    #[BlockField(type: 'string')]
    public string $heading = '';

    #[BlockField(type: 'richtext')]
    public string $body = '';

    #[BlockField(type: 'blocks', allowed: ['hero'])]
    public array $items = [];
}
