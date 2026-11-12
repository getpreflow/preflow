<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures;

use Preflow\Blocks\Block;

/** Declares the key App\Blocks\Quote has. */
#[Block(key: 'quote', label: 'Another quote')]
final class DuplicateQuote
{
}
