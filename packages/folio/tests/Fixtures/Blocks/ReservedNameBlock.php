<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Folio\Block\Block;
use Preflow\Folio\Block\BlockField;

/** Declares a field named like a system key: must be rejected. */
#[Block(key: 'reserved_name', label: 'Reserved')]
final class ReservedNameBlock
{
    #[BlockField(type: 'string')]
    public string $_order = '';
}
