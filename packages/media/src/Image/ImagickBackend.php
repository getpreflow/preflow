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
                $copy->thumbnailImage($job['width'], 0);
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
}
