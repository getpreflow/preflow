<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** Data-only block with a link field (0.91): a post or a web address. */
#[Block(key: 'call_to_action', label: 'Call to action')]
final class CallToActionBlock
{
    #[BlockField(type: 'string')]
    public string $heading = '';

    /** @var array<string, mixed>|null */
    #[BlockField(type: 'link', label: 'Button', config: ['link' => ['kinds' => ['record', 'url'], 'types' => ['post']]])]
    public ?array $button = null;

    /** @var array<string, mixed>|null a file to download: the picker's field names nest in the block */
    #[BlockField(type: 'link', label: 'Download', config: ['link' => ['kinds' => ['asset']]])]
    public ?array $download = null;
}
