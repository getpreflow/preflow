<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Columns;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** An areas field over a set no package registers: the registry refuses it. */
#[Block(key: 'stray_grid', label: 'Stray grid')]
final class Unregistered
{
    #[BlockField(type: 'areas', config: ['layouts' => 'missing'])]
    public array $cells = [];
}
