<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

final class ImagickBackend implements ImageBackend
{
    public function supports(string $format): bool
    {
        return \Imagick::queryFormats(strtoupper($format)) !== [];
    }

    public function writeVariants(string $src, array $jobs, int $quality): array
    {
        try {
            $image = new \Imagick($src);
            $image->autoOrient();
        } catch (\ImagickException) {
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
