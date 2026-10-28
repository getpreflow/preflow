<?php

declare(strict_types=1);

namespace Preflow\Media\Cleanup;

use Preflow\Media\Asset;

/** An asset cleanup would move into the bin, and why it counts as unused (which sources were asked). */
final readonly class CleanupCandidate
{
    public function __construct(
        public Asset $asset,
        public string $reason,
    ) {}
}
