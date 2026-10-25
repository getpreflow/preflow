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
 */
final class PosterReferences implements AssetReferenceSource
{
    public const string NAME = 'media';
    public const string NOTE = 'as poster';

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
        $usages = [];
        foreach (($this->library)()->all() as $asset) {
            if ($asset->posterId === $assetId) {
                $usages[] = new AssetUsageEntry($asset->label(), $this->editUrl !== null ? ($this->editUrl)($asset) : null, self::NAME, self::NOTE);
            }
        }

        return $usages;
    }
}
