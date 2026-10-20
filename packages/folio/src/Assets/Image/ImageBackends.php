<?php

declare(strict_types=1);

namespace Preflow\Folio\Assets\Image;

final class ImageBackends
{
    /** GD when it can write WebP, else Imagick, else none (no variants are made). */
    public static function detect(): ?ImageBackend
    {
        if (extension_loaded('gd') && function_exists('imagewebp')) {
            return new GdBackend();
        }
        if (class_exists(\Imagick::class)) {
            $imagick = new ImagickBackend();
            return $imagick->supports('webp') ? $imagick : null;
        }

        return null;
    }
}
