<?php

declare(strict_types=1);

namespace Preflow\Media\Usage;

/**
 * Where an asset is used, asked of every registered source in registration
 * order. MediaLibrary::delete() refuses an asset while this reports usages.
 */
final class AssetUsage
{
    /** @var list<AssetReferenceSource> */
    private array $sources = [];

    public function register(AssetReferenceSource $source): void
    {
        $this->sources[] = $source;
    }

    /** @return list<AssetUsageEntry> */
    public function of(string $assetId): array
    {
        $usages = [];
        foreach ($this->sources as $source) {
            array_push($usages, ...$source->usagesOf($assetId));
        }

        return $usages;
    }

    public function isUsed(string $assetId): bool
    {
        return $this->of($assetId) !== [];
    }

    /** @return list<string> the registered sources' names, in order */
    public function sourceNames(): array
    {
        return array_map(static fn (AssetReferenceSource $s): string => $s->name(), $this->sources);
    }
}
