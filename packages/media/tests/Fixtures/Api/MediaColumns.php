<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Fixtures\Api;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A data-only block with columns (an areas field over the set "pair"), as the columns block has. */
#[Block(key: 'media_columns', label: 'Columns')]
final class MediaColumns
{
    #[BlockField(type: 'areas', config: ['layouts' => 'pair'])]
    public array $columns = [];
}
