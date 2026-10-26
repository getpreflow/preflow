<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Support;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Media\Asset;
use Preflow\Media\MediaLibrary;

/**
 * Test-only: store an asset with some of its data changed (colours, a preview, a note), as
 * the library itself would after a run of its tools, without going through them.
 */
final class StoredAssets
{
    /** @param array<string, mixed> $changes storage keys of Asset::toData() */
    public static function patch(DataManager $dm, MediaLibrary $library, Asset $asset, array $changes): Asset
    {
        $dm->saveType(DynamicRecord::fromArray($library->definition(), [...$asset->toData(), ...$changes]), false);

        return $library->find($asset->id) ?? throw new \RuntimeException("Asset {$asset->id} is gone.");
    }
}
