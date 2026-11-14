<?php

declare(strict_types=1);

namespace Preflow\Media\Usage;

/**
 * A usage source that counts the usages of many assets at once (a library page of cards
 * asks for all of them). AssetUsage::counts() asks a source that implements it once per
 * page; any other source is asked usagesOf() per asset. The count for an id must equal
 * the number of usagesOf($id) that block a delete (AssetUsageEntry::$blocksDelete), and
 * with $nonBlocking count(usagesOf($id)). A source can skip reading what only holds
 * non-blocking usages (Folio's earlier revisions) when $nonBlocking is false.
 *
 * @since 0.76
 */
interface CountsAssetUsage
{
    /**
     * @param list<string> $assetIds
     * @param bool $nonBlocking also count the usages that do not block a delete
     * @return array<string, int> asset id => its number of usages (an id may be missing for 0)
     */
    public function usageCounts(array $assetIds, bool $nonBlocking = false): array;
}
