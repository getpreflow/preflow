<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** Data-only block holding polls (PollBlock) as nested blocks. */
#[Block(key: 'poll_group', label: 'Poll group')]
final class PollGroupBlock
{
    #[BlockField(type: 'string')]
    public string $title = '';

    #[BlockField(type: 'blocks', allowed: ['poll'])]
    public array $polls = [];
}
