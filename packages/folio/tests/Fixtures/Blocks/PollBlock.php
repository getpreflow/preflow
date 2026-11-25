<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** Data-only block whose selects take their options from the block's own tags ("options_from", 0.90). */
#[Block(key: 'poll', label: 'Poll')]
final class PollBlock
{
    #[BlockField(type: 'tags', label: 'Possible answers')]
    public array $answers = [];

    #[BlockField(type: 'select', config: ['options_from' => 'answers', 'display' => 'select'])]
    public string $answer = '';

    #[BlockField(type: 'select', validate: ['required'], config: ['options_from' => 'answers', 'display' => 'radio'])]
    public string $fallback = '';
}
