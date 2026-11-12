<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks\BoomBlock;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;

/** Always fails to render: the error boundary must contain it. */
#[Block(key: 'boom', label: 'Boom')]
final class BoomBlock extends BlockComponent
{
    public function resolveState(): void
    {
        throw new \RuntimeException('boom block failed');
    }
}
