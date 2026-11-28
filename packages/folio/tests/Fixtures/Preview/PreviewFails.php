<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Preview;

use Preflow\Components\Component;

/** A type preview's component that fails while it renders. */
final class PreviewFails extends Component
{
    public function resolveState(): void
    {
        throw new \RuntimeException('The card could not read its record.');
    }
}
