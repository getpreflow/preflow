<?php

declare(strict_types=1);

namespace Preflow\Media\Usage;

/** Thrown by MediaLibrary::delete() for an asset that is still used; nothing was deleted. */
final class AssetInUseException extends \RuntimeException
{
    /** @param list<AssetUsageEntry> $usages */
    public function __construct(string $assetId, public readonly array $usages)
    {
        $n = count($usages);
        parent::__construct("Asset {$assetId} is used in {$n} place" . ($n === 1 ? '' : 's') . '; remove it there first.');
    }
}
