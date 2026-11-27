<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A poll whose answers field is labelled by a translation key, for messages that name it. */
#[Block(key: 'labelled_poll', label: 'Labelled poll')]
final class LabelledPollBlock
{
    #[BlockField(type: 'tags', label: 'site.blocks.poll.answers')]
    public array $answers = [];

    #[BlockField(type: 'select', config: ['options_from' => 'answers'])]
    public string $answer = '';
}
