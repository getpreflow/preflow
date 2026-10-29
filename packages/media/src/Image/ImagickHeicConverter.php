<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

/**
 * HEIC and HEIF through Imagick's libheif delegate (HEIF files are read by the same delegate).
 * libheif applies the container's rotation and mirroring (irot, imir) when it decodes, so the
 * pixels come out upright and the JPEG says orientation 1. The ICC profile and EXIF are kept,
 * so a Display P3 phone photo gets colour-managed variants like any JPEG.
 *
 * The header is read first (ping): a photo above $maxPixels is refused (HeicTooLarge) before
 * it is decoded, so a huge or hostile file cannot exhaust memory.
 */
final class ImagickHeicConverter implements HeicConverter
{
    public const int QUALITY = 92;

    /** 48 MP phone photos pass with room to spare. */
    public const int DEFAULT_MAX_PIXELS = 100_000_000;

    public function __construct(private readonly int $maxPixels = self::DEFAULT_MAX_PIXELS) {}

    public function available(): bool
    {
        return class_exists(\Imagick::class) && \Imagick::queryFormats('HEIC') !== [];
    }

    /** @throws HeicTooLarge above $maxPixels (nothing decoded) */
    public function toJpeg(string $bytes): string
    {
        if (!$this->available()) {
            throw new \RuntimeException('Imagick has no HEIC support.');
        }
        $this->checkSize($bytes);
        $image = new \Imagick();
        try {
            $image->readImageBlob($bytes);
            $image->setFirstIterator(); // the primary image, not a thumbnail or a depth map
            $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(self::QUALITY);
            $jpeg = $image->getImageBlob();
        } catch (\ImagickException $e) {
            throw new \RuntimeException('The HEIC/HEIF image could not be decoded: ' . $e->getMessage(), 0, $e);
        } finally {
            $image->clear();
        }
        if ($jpeg === '') {
            throw new \RuntimeException('The HEIC/HEIF image could not be decoded.');
        }

        return $jpeg;
    }

    /** @throws HeicTooLarge when the images in the file hold more than $maxPixels together */
    private function checkSize(string $bytes): void
    {
        $ping = new \Imagick();
        try {
            $ping->pingImageBlob($bytes);
            $pixels = 0;
            foreach ($ping as $frame) {
                $pixels += $frame->getImageWidth() * $frame->getImageHeight();
            }
        } catch (\ImagickException $e) {
            throw new \RuntimeException('The HEIC/HEIF image could not be read: ' . $e->getMessage(), 0, $e);
        } finally {
            $ping->clear();
        }
        if ($pixels > $this->maxPixels) {
            throw new HeicTooLarge("The HEIC/HEIF image has {$pixels} pixels, more than the {$this->maxPixels} it may have.");
        }
    }
}
