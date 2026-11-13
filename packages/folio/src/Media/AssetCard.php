<?php

declare(strict_types=1);

namespace Preflow\Folio\Media;

use Preflow\Folio\FolioText;
use Preflow\Media\Asset;
use Preflow\Media\MediaLibrary;

/**
 * What the asset field's card shows for one library asset (0.75), in one shape for all three places
 * that build it: the field's own cards (AssetFieldType), the picker's Select buttons (_pick.twig)
 * and the upload route's JSON answer (the field's drop). admin.js's Folio.fillAssetField reads it.
 *
 * - thumb: the thumbnail URL, '' without one;
 * - label: the title, else the file name;
 * - meta: the kind and the size ("image · 160 × 100");
 * - warn: an image without alt text in $locale (else $default);
 * - focal: "x,y" (percent) for an image with a thumbnail, else ''.
 *
 * @since 0.75
 */
final class AssetCard
{
    /** @return array{id: string, thumb: string, label: string, meta: string, warn: bool, focal: string} */
    public static function of(Asset $asset, MediaLibrary $library, string $locale, string $default): array
    {
        $kind = $asset->kind();
        $thumb = (string) ($library->thumbnailUrl($asset) ?? '');

        return [
            'id' => $asset->id,
            'thumb' => $thumb,
            'label' => $asset->label(),
            'meta' => FolioText::get('folio.assets.kind_names.' . $kind)
                . ($asset->width !== null && $asset->height !== null ? ' · ' . $asset->width . ' × ' . $asset->height : ''),
            'warn' => $kind === 'image' && trim($asset->altFor($locale, $default)) === '',
            'focal' => $kind === 'image' && $thumb !== '' ? $asset->focalX . ',' . $asset->focalY : '',
        ];
    }
}
