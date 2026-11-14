<?php

declare(strict_types=1);

namespace Preflow\Media\Usage;

/**
 * A usage source that counts the usages of many assets at once (a library page of cards
 * asks for all of them). AssetUsage::counts() asks a source that implements it once per
 * page; any other source is asked usagesOf() per asset. The count for an id must equal
 * count(usagesOf($id)).
 */
interface CountsAssetUsage
{
    /**
     * @param list<string> $assetIds
     * @return array<string, int> asset id => its number of usages (an id may be missing for 0)
     */
    public function usageCounts(array $assetIds): array;
}
