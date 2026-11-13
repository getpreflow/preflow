<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** Data-only block whose label needs escaping ("Q&A"). */
#[Block(key: 'qa', label: 'Q&A')]
final class QaBlock
{
    #[BlockField(type: 'string')]
    public string $question = '';
}
