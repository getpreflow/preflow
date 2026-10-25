<?php

declare(strict_types=1);

namespace Preflow\Media\Usage;

/**
 * Something that stores asset references and reports them: every place its data
 * references an asset (by id, and by legacy path where its data can hold those).
 * An app that stores references and registers no source gets no delete
 * protection there. How a source answers (scanning on request or an index kept
 * up to date on save) is its own choice.
 */
interface AssetReferenceSource
{
    /** A short, stable name (e.g. "folio"), set as AssetUsageEntry::$source. */
    public function name(): string;

    /** @return list<AssetUsageEntry> */
    public function usagesOf(string $assetId): array;
}
