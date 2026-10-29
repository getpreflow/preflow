<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

/**
 * HEIC and HEIF through Imagick's libheif delegate (HEIF files are read by the same delegate).
 * libheif applies the container's rotation and mirroring (irot, imir) when it decodes, so the
 * pixels come out upright and the JPEG says orientation 1. The ICC profile and EXIF are kept,
 * so a Display P3 phone photo gets colour-managed variants like any JPEG.
 */
final class ImagickHeicConverter implements HeicConverter
{
    public const int QUALITY = 92;

    public function available(): bool
    {
        return class_exists(\Imagick::class) && \Imagick::queryFormats('HEIC') !== [];
    }

    public function toJpeg(string $bytes): string
    {
        if (!$this->available()) {
            throw new \RuntimeException('Imagick has no HEIC support.');
        }
        try {
            $image = new \Imagick();
            $image->readImageBlob($bytes);
            $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(self::QUALITY);
            $jpeg = $image->getImageBlob();
            $image->clear();
        } catch (\ImagickException $e) {
            throw new \RuntimeException('The HEIC/HEIF image could not be decoded: ' . $e->getMessage(), 0, $e);
        }
        if ($jpeg === '') {
            throw new \RuntimeException('The HEIC/HEIF image could not be decoded.');
        }

        return $jpeg;
    }
}
