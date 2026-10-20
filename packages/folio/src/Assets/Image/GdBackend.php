<?php

declare(strict_types=1);

namespace Preflow\Folio\Assets\Image;

final class GdBackend implements ImageBackend
{
    public function supports(string $format): bool
    {
        return match ($format) {
            'webp' => function_exists('imagewebp'),
            'avif' => function_exists('imageavif'),
            default => false,
        };
    }

    public function resize(string $src, string $dest, int $width, string $format, int $quality): bool
    {
        $image = @imagecreatefromstring((string) file_get_contents($src));
        if ($image === false) {
            return false;
        }
        $height = max(1, (int) round(imagesy($image) * $width / max(1, imagesx($image))));
        $scaled = imagecreatetruecolor($width, $height);
        imagealphablending($scaled, false); // keep transparency (PNG, WebP)
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return match ($format) {
            'webp' => imagewebp($scaled, $dest, $quality),
            'avif' => imageavif($scaled, $dest, $quality, 6), // speed 6: eager generation must stay fast
            default => false,
        };
    }
}
