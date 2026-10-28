<?php

declare(strict_types=1);

namespace Preflow\Media\Usage;

use Preflow\Media\Asset;
use Preflow\Media\MediaLibrary;

/**
 * The library's own references: an asset used as another asset's video poster.
 * Registered by MediaServiceProvider, so every site gets it. The entry's URL is
 * where the referencing asset is edited, when a UI says so (Folio: its library
 * screen); the core has none.
 *
 * The poster of an asset in the bin is reported too, without blocking a delete:
 * the poster can be binned by hand, but emptying the bin and cleanup leave it
 * alone while its asset can still be restored.
 */
final class PosterReferences implements AssetReferenceSource
{
    public const string NAME = 'media';
    public const string NOTE = 'as poster';
    public const string NOTE_BINNED = 'poster of a binned asset';

    /**
     * @param \Closure(): MediaLibrary $library resolved on first use (the library itself asks this source)
     * @param (\Closure(Asset): ?string)|null $editUrl
     */
    public function __construct(
        private readonly \Closure $library,
        private readonly ?\Closure $editUrl = null,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /** @return list<AssetUsageEntry> */
    public function usagesOf(string $assetId): array
    {
        $live = $binned = [];
        foreach (($this->library)()->assetsWithPoster($assetId) as $asset) {
            if ($asset->binnedAt === null) {
                $live[] = new AssetUsageEntry($asset->label(), $this->editUrl !== null ? ($this->editUrl)($asset) : null, self::NAME, self::NOTE);
            } else {
                // A binned asset has no screen to edit it on: no URL.
                $binned[] = new AssetUsageEntry($asset->label(), null, self::NAME, self::NOTE_BINNED, blocksDelete: false);
            }
        }

        return [...$live, ...$binned];
    }
}
