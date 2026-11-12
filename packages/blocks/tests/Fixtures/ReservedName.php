<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

#[Block(key: 'reserved', label: 'Reserved')]
final class ReservedName
{
    #[BlockField]
    public string $_order = '';
}
