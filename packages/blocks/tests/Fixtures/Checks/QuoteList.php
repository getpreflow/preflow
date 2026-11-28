<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Checks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A block whose nested quotes are limited: at least one, at most two. */
#[Block(key: 'quote_list', label: 'Quote list')]
final class QuoteList
{
    #[BlockField(type: 'blocks', allowed: ['section_quote'], min: 1, max: 2)]
    public array $quotes = [];
}
