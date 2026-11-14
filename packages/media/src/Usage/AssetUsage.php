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
     * How many usages each asset has, for a whole page of assets at once: a source that
     * implements CountsAssetUsage is asked once, any other per asset. Every given id is a key;
     * an unused one counts 0.
     *
     * By default only the usages that block a delete count (count(blocking($id)) for each): a
     * library card's "used N×", which a source can answer without reading what holds only
     * non-blocking usages (Folio's earlier revisions, a scan of every revision on each page
     * view). With $nonBlocking every usage counts (count(of($id)) for each), as cleanup and
     * emptying the bin see them (of(), isUsed()).
     *
     * @since 0.76
     * @param list<string> $assetIds
     * @param bool $nonBlocking also count the usages that do not block a delete (an earlier revision, the poster of a binned asset)
     * @return array<string, int>
     */
    public function counts(array $assetIds, bool $nonBlocking = false): array
    {
        $ids = array_values(array_unique(array_filter($assetIds, 'is_string')));
        $counts = array_fill_keys($ids, 0);
        if ($ids === []) {
            return $counts;
        }
        foreach ($this->ordered() as $source) {
            if ($source instanceof CountsAssetUsage) {
                $batch = $source->usageCounts($ids, $nonBlocking);
                foreach ($ids as $id) {
                    $counts[$id] += max(0, (int) ($batch[$id] ?? 0));
                }
                continue;
            }
            foreach ($ids as $id) {
                $counts[$id] += self::countOf($source->usagesOf($id), $nonBlocking);
            }
        }

        return $counts;
    }

    /**
     * @param list<AssetUsageEntry> $entries
     * @internal what CountsAssetUsage::usageCounts() answers for a source without a batch
     */
    public static function countOf(array $entries, bool $nonBlocking): int
    {
        return $nonBlocking ? count($entries) : count(array_filter($entries, static fn (AssetUsageEntry $e): bool => $e->blocksDelete));
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
