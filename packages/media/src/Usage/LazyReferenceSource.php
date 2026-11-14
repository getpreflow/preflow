<?php

declare(strict_types=1);

namespace Preflow\Media\Usage;

/**
 * A usage source registered by name now and resolved when an asset is checked,
 * e.g. from the container: what it needs is not built at boot, and a binding
 * replaced later (Folio re-binds PosterReferences) is the one asked. Counts go to the
 * source's own batch (CountsAssetUsage) when it has one, else to usagesOf() per asset.
 */
final class LazyReferenceSource implements AssetReferenceSource, CountsAssetUsage
{
    /** @param \Closure(): AssetReferenceSource $source called on every check */
    public function __construct(
        private readonly string $name,
        private readonly \Closure $source,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<AssetUsageEntry> */
    public function usagesOf(string $assetId): array
    {
        return ($this->source)()->usagesOf($assetId);
    }

    /**
     * @param list<string> $assetIds
     * @return array<string, int>
     */
    public function usageCounts(array $assetIds, bool $nonBlocking = false): array
    {
        $source = ($this->source)();
        if ($source instanceof CountsAssetUsage) {
            return $source->usageCounts($assetIds, $nonBlocking);
        }
        $counts = [];
        foreach ($assetIds as $id) {
            $counts[$id] = AssetUsage::countOf($source->usagesOf($id), $nonBlocking);
        }

        return $counts;
    }
}
