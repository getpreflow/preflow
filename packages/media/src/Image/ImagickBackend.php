<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

final class ImagickBackend implements ImageBackend
{
    /** @param ?ColorManagement $color converts every source to sRGB (those that need it) */
    public function __construct(private readonly ?ColorManagement $color = null) {}

    public function supports(string $format): bool
    {
        return \Imagick::queryFormats(strtoupper($format)) !== [];
    }

    public function writeVariants(string $src, array $jobs, int $quality): array
    {
        try {
            $image = $this->color?->open($src) ?? new \Imagick($src);
            $image->autoOrient();
            $this->color?->toSrgb($image);
        } catch (\ImagickException|\RuntimeException) { // RuntimeException: a profile that cannot be read
            return array_fill(0, count($jobs), false);
        }

        $results = [];
        foreach ($jobs as $job) {
            try {
                $copy = clone $image;
                if (isset($job['crop'])) {
                    $copy->cropImage($job['crop']['w'], $job['crop']['h'], $job['crop']['x'], $job['crop']['y']);
                    $copy->setImagePage(0, 0, 0, 0);
                    $copy->thumbnailImage($job['width'], max(1, (int) round($job['crop']['h'] * $job['width'] / $job['crop']['w'])));
                } else {
                    $copy->thumbnailImage($job['width'], 0);
                }
                $copy->setImageFormat($job['format']);
                $copy->setImageCompressionQuality($quality);
                $results[] = $copy->writeImage($job['dest']);
                $copy->clear();
            } catch (\ImagickException) {
                $results[] = false;
            }
        }
        $image->clear();

        return $results;
    }

    public function writeEdited(string $src, string $dest, int $quarterTurns, bool $mirror, ?array $crop, string $format, int $quality, int $maxLongSide = 0): bool
    {
        try {
            $image = $this->color?->open($src) ?? new \Imagick($src);
            $image->autoOrient();
            $this->color?->toSrgb($image);
            if ($mirror) {
                $image->flopImage();
            }
            $turns = (($quarterTurns % 4) + 4) % 4;
            if ($turns > 0) {
                $image->rotateImage(new \ImagickPixel('none'), 90 * $turns);
                $image->setImagePage(0, 0, 0, 0);
            }
            if ($crop !== null) {
                $image->cropImage($crop['w'], $crop['h'], $crop['x'], $crop['y']);
                $image->setImagePage(0, 0, 0, 0);
            }
            if ($maxLongSide > 0 && max($image->getImageWidth(), $image->getImageHeight()) > $maxLongSide) {
                $image->thumbnailImage($maxLongSide, $maxLongSide, true);
            }
            if ($format === 'jpeg') {
                $image->setImageBackgroundColor('white'); // JPEG has no alpha
                $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            }
            $image->stripImage();
            $image->setImageFormat($format);
            $image->setImageCompressionQuality($quality);
            $ok = $image->writeImage($dest);
            $image->clear();

            return $ok;
        } catch (\ImagickException|\RuntimeException) {
            return false;
        }
    }
}
