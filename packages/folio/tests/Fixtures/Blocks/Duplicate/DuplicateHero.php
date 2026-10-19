<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks\Duplicate;

use Preflow\Folio\Block\Block;

#[Block(key: 'hero', label: 'Another hero')]
final class DuplicateHero
{
}
