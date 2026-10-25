<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

/** EXIF orientation of JPEGs (phones store photos sideways plus a rotation tag). */
final class Orientation
{
    /** 1 (upright) when unknown, not a JPEG, or the exif extension is missing. */
    public static function of(string $file): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        $type = @exif_imagetype($file);
        if ($type !== IMAGETYPE_JPEG) {
            return 1;
        }
        $exif = @exif_read_data($file);
        $value = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $value >= 1 && $value <= 8 ? $value : 1;
    }

    /** Orientations 5–8 turn the image by 90°: displayed width and height swap. */
    public static function swapsAxes(int $orientation): bool
    {
        return $orientation >= 5;
    }
}
