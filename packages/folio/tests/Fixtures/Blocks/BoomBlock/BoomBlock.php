<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks\BoomBlock;

use Preflow\Folio\Block\Block;
use Preflow\Folio\Block\BlockComponent;

/** Always fails to render: the error boundary must contain it. */
#[Block(key: 'boom', label: 'Boom')]
final class BoomBlock extends BlockComponent
{
    public function resolveState(): void
    {
        throw new \RuntimeException('boom block failed');
    }
}
