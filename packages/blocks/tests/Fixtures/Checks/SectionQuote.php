<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Checks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A quote inside a section: its text is required. */
#[Block(key: 'section_quote', label: 'Section quote')]
final class SectionQuote
{
    #[BlockField(type: 'text', validate: ['required'])]
    public string $text = '';
}
