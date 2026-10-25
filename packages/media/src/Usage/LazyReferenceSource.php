<?php

declare(strict_types=1);

namespace Preflow\Media\Usage;

/**
 * A usage source registered by name now and resolved when an asset is checked,
 * e.g. from the container: what it needs is not built at boot, and a binding
 * replaced later (Folio re-binds PosterReferences) is the one asked.
 */
final class LazyReferenceSource implements AssetReferenceSource
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
}
