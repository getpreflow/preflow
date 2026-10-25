<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

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

    public function writeVariants(string $src, array $jobs, int $quality): array
    {
        $image = @imagecreatefromstring((string) file_get_contents($src));
        if ($image === false) {
            return array_fill(0, count($jobs), false);
        }
        $image = self::upright($image, Orientation::of($src));
        [$w, $h] = [imagesx($image), imagesy($image)];

        $results = [];
        foreach ($jobs as $job) {
            $height = max(1, (int) round($h * $job['width'] / max(1, $w)));
            $scaled = imagecreatetruecolor($job['width'], $height);
            imagealphablending($scaled, false); // keep transparency (PNG, WebP)
            imagesavealpha($scaled, true);
            imagecopyresampled($scaled, $image, 0, 0, 0, 0, $job['width'], $height, $w, $h);
            $results[] = match ($job['format']) {
                'webp' => imagewebp($scaled, $job['dest'], $quality),
                'avif' => imageavif($scaled, $job['dest'], $quality, 6), // speed 6: eager generation must stay fast
                default => false,
            };
            unset($scaled);
        }

        return $results;
    }

    /** Apply the EXIF orientation (imagerotate turns counterclockwise). */
    private static function upright(\GdImage $image, int $orientation): \GdImage
    {
        $rotate = static fn (\GdImage $i, int $deg): \GdImage => imagerotate($i, $deg, 0) ?: $i;
        $image = match ($orientation) {
            3, 4 => $rotate($image, 180),
            5, 6 => $rotate($image, 270),
            7, 8 => $rotate($image, 90),
            default => $image,
        };
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }
}
