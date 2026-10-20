<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks\NeedyBlock;

use Preflow\Folio\Block\Block;
use Preflow\Folio\Block\BlockComponent;

/** Its constructor dependency cannot be resolved by the container. */
#[Block(key: 'needy', label: 'Needy')]
final class NeedyBlock extends BlockComponent
{
    public function __construct(private readonly string $notInjectable)
    {
    }
}
