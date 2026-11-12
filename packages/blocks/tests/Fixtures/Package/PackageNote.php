<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Package;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A block an installed package declares (composer.json extra.preflow.blocks). */
#[Block(key: 'package_note', label: 'Package note', group: 'Package')]
final class PackageNote
{
    #[BlockField(type: 'string')]
    public string $note = '';
}
