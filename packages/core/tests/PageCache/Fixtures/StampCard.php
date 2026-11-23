<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache\Fixtures;

use Preflow\Components\Component;

/** A component that prints a value that is new on every render, for every visitor. */
final class StampCard extends Component
{
    public string $stamp = '';

    public function resolveState(): void
    {
        $this->stamp = bin2hex(random_bytes(8));
    }
}
