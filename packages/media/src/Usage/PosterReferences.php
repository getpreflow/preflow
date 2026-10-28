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

    /** Binned assets read per page while looking for posters. */
    private const int PAGE = 200;

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
        $library = ($this->library)();
        $usages = [];
        foreach ($library->all() as $asset) {
            if ($asset->posterId === $assetId) {
                $usages[] = new AssetUsageEntry($asset->label(), $this->editUrl !== null ? ($this->editUrl)($asset) : null, self::NAME, self::NOTE);
            }
        }
        // A binned asset has no screen to edit it on: no URL.
        for ($page = 1; ; $page++) {
            $binned = $library->binned($page, self::PAGE);
            foreach ($binned->page === $page ? $binned->items : [] as $asset) {
                if ($asset->posterId === $assetId) {
                    $usages[] = new AssetUsageEntry($asset->label(), null, self::NAME, self::NOTE_BINNED, blocksDelete: false);
                }
            }
            if ($binned->page !== $page || $page * self::PAGE >= $binned->total) {
                break;
            }
        }

        return $usages;
    }
}
