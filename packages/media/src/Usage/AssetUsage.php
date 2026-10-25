<?php

declare(strict_types=1);

namespace Preflow\Media\Usage;

use Preflow\Core\Container\Attributes\Singleton;

/**
 * Where an asset is used, asked of every registered source in registration
 * order, then of the sources registered as last (the core's poster source, so
 * poster rows list after record rows). MediaLibrary::delete() refuses an asset
 * while this reports usages.
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
