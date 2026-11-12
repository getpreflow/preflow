<?php

declare(strict_types=1);

namespace Preflow\Media\Pdf;

use Preflow\Media\MediaLibrary;
use Preflow\View\Pdf\PdfImage;
use Preflow\View\Pdf\PdfImageSource;

/**
 * pdf_image() by asset id: the media library's answer to view's PdfImageSource.
 * An edited image (crop, rotation) is printed as it is shown, its rendered master.
 */
final class MediaPdfImages implements PdfImageSource
{
    public function __construct(private readonly MediaLibrary $library)
    {
    }

    public function find(string $id): ?PdfImage
    {
        $asset = $this->library->find($id);
        if ($asset === null) {
            return null;
        }
        $shown = $this->library->deliveryAsset($asset) ?? $asset;

        return new PdfImage(
            $this->library->file($shown) ?? '',
            $this->library->url($asset),
            $asset->private,
            $asset->kind() === 'image',
        );
    }
}
