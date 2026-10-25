<?php

declare(strict_types=1);

namespace Preflow\Media\Usage;

/** One place an asset is used: what to call it, where to edit it, which source found it, and an optional note ("published version"). */
final readonly class AssetUsageEntry
{
    public function __construct(
        public string $label,
        public ?string $url,
        public string $source,
        public ?string $note = null,
    ) {}
}
