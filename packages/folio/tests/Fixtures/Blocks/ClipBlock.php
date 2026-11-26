<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** Data-only block whose embed field lists a provider a site adds (0.91). */
#[Block(key: 'clip', label: 'Clip')]
final class ClipBlock
{
    /** @var array<string, mixed>|null */
    #[BlockField(type: 'embed', label: 'Clip', config: ['providers' => ['peertube']])]
    public ?array $clip = null;
}
