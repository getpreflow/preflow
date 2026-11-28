<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Preview;

use Preflow\Components\Component;

/** A preview view's component that fails while it renders: its error boundary shows instead. */
final class PreviewBroken extends Component
{
    public function resolveState(): void
    {
        throw new \RuntimeException('The teaser could not load its record.');
    }
}
