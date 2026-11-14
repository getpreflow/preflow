<?php

declare(strict_types=1);

namespace Preflow\Media\Usage;

use Preflow\Core\Container\Attributes\Singleton;

/**
 * Where an asset is used, asked of every registered source in registration
 * order, then of the sources registered as last (the core's poster source, so
 * poster rows list after record rows). MediaLibrary::delete() refuses an asset
 * while this reports a usage that blocks a delete (blocking()); emptying the bin
 * skips an asset while it reports any usage (of()).
 *
 * One per container from its first resolution, whichever provider asks first,
 * so a source registered before MediaServiceProvider is kept.
 */
#[Singleton]
final class AssetUsage
{
    /** @var list<AssetReferenceSource> */
    private array $sources = [];

    /** @var list<AssetReferenceSource> */
    private array $last = [];

    /** @param bool $last asked after every source registered without it */
    public function register(AssetReferenceSource $source, bool $last = false): void
    {
        if ($last) {
            $this->last[] = $source;
        } else {
            $this->sources[] = $source;
        }
    }

    /** @return list<AssetUsageEntry> */
    public function of(string $assetId): array
    {
        $usages = [];
        foreach ($this->ordered() as $source) {
            array_push($usages, ...$source->usagesOf($assetId));
        }

        return $usages;
    }

    /** @return list<AssetUsageEntry> the usages that refuse a delete (AssetUsageEntry::$blocksDelete) */
    public function blocking(string $assetId): array
    {
        return array_values(array_filter($this->of($assetId), static fn (AssetUsageEntry $e): bool => $e->blocksDelete));
    }

    /**
     * How many usages each asset has (count(of($id)) for each), for a whole page of assets at once:
     * a source that implements CountsAssetUsage is asked once, any other per asset. Every given id
     * is a key; an unused one counts 0.
     *
     * @param list<string> $assetIds
     * @return array<string, int>
     */
    public function counts(array $assetIds): array
    {
        $ids = array_values(array_unique(array_filter($assetIds, 'is_string')));
        $counts = array_fill_keys($ids, 0);
        if ($ids === []) {
            return $counts;
        }
        foreach ($this->ordered() as $source) {
            if ($source instanceof CountsAssetUsage) {
                $batch = $source->usageCounts($ids);
                foreach ($ids as $id) {
                    $counts[$id] += max(0, (int) ($batch[$id] ?? 0));
                }
                continue;
            }
            foreach ($ids as $id) {
                $counts[$id] += count($source->usagesOf($id));
            }
        }

        return $counts;
    }

    public function isUsed(string $assetId): bool
    {
        return $this->of($assetId) !== [];
    }

    /** @return list<string> the registered sources' names, in the order they are asked */
    public function sourceNames(): array
    {
        return array_map(static fn (AssetReferenceSource $s): string => $s->name(), $this->ordered());
    }

    /** @return list<AssetReferenceSource> */
    private function ordered(): array
    {
        return [...$this->sources, ...$this->last];
    }
}
