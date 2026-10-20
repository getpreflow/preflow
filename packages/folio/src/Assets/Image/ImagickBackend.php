<?php

declare(strict_types=1);

namespace Preflow\Folio\Assets\Image;

final class ImagickBackend implements ImageBackend
{
    public function supports(string $format): bool
    {
        return \Imagick::queryFormats(strtoupper($format)) !== [];
    }

    public function resize(string $src, string $dest, int $width, string $format, int $quality): bool
    {
        try {
            $image = new \Imagick($src);
            $image->autoOrient();
            $image->thumbnailImage($width, 0);
            $image->setImageFormat($format);
            $image->setImageCompressionQuality($quality);
            $ok = $image->writeImage($dest);
            $image->clear();

            return $ok;
        } catch (\ImagickException) {
            return false;
        }
    }
}
