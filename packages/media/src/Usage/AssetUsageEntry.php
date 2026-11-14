<?php

declare(strict_types=1);

namespace Preflow\Media\Usage;

/**
 * One place an asset is used: what to call it, where to edit it, which source found it, and an optional note ("published version").
 * $type is a key the source defines for what uses it (e.g. a content type's key, or "asset" for another asset), so a UI
 * can show that kind's icon and name; null when the source has none.
 * An entry that does not block a delete (an earlier revision) still keeps the asset from being deleted for good: a manual
 * delete moves it into the bin, from where it can be restored; cleanup and emptying the bin leave it alone.
 */
final readonly class AssetUsageEntry
{
    public function __construct(
        public string $label,
        public ?string $url,
        public string $source,
        public ?string $note = null,
        public bool $blocksDelete = true,
        public ?string $type = null,
    ) {}
}
