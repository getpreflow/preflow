<?php

declare(strict_types=1);

namespace Preflow\Fields;

/**
 * Optional for a RecordAddresses: where a file of the media library is delivered, for a link that
 * points at it (a link of kind "asset"). Without it a file link renders without an href. Folio's
 * RecordAddresses implements it with the media library's URL of the asset.
 *
 * @since 0.91.0
 */
interface AssetAddresses
{
    /** The file's address ("/uploads/prices.pdf"); null when the asset is gone. */
    public function assetAddressOf(string $id): ?string;
}
