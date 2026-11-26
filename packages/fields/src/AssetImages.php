<?php

declare(strict_types=1);

namespace Preflow\Fields;

/**
 * Optional for a RecordAddresses (next to AssetAddresses): whether an asset id is an image of the
 * media library. The embed field's check asks it, so a poster is an existing image also when a
 * value is written past an editor (the REST API, an import). Folio's RecordAddresses implements
 * it with the media library. Without it (a site without a media library) a poster is checked for
 * its shape only: an id as text.
 *
 * @since 0.91.0
 */
interface AssetImages
{
    /** True for an image of the library, false for a file that is no image or no file at all; null when it cannot tell (no library storage). */
    public function isImage(string $id): ?bool;
}
