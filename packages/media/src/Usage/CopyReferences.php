<?php

declare(strict_types=1);

namespace Preflow\Media\Usage;

use Preflow\Media\MediaLibrary;

/**
 * The library's copies as usages of their original: an original is not deleted
 * while copies point at it (the copies go first). Registered by
 * MediaServiceProvider, so every site gets it. The entry has no URL; a UI that
 * links copies does so itself.
 */
final class CopyReferences implements AssetReferenceSource
{
    public const string NAME = 'copies';
    public const string NOTE = 'copy';

    /** @param \Closure(): MediaLibrary $library resolved on first use (the library itself asks this source) */
    public function __construct(
        private readonly \Closure $library,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /** @return list<AssetUsageEntry> */
    public function usagesOf(string $assetId): array
    {
        $usages = [];
        foreach (($this->library)()->copies($assetId) as $copy) {
            $usages[] = new AssetUsageEntry($copy->label(), null, self::NAME, self::NOTE);
        }

        return $usages;
    }
}
