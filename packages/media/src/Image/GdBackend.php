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
            $crop = $job['crop'] ?? null;
            $height = max(1, (int) round(($crop['h'] ?? $h) * $job['width'] / max(1, $crop['w'] ?? $w)));
            $scaled = imagecreatetruecolor($job['width'], $height);
            imagealphablending($scaled, false); // keep transparency (PNG, WebP)
            imagesavealpha($scaled, true);
            if ($crop !== null) {
                imagecopyresampled($scaled, $image, 0, 0, $crop['x'], $crop['y'], $job['width'], $height, $crop['w'], $crop['h']);
            } else {
                imagecopyresampled($scaled, $image, 0, 0, 0, 0, $job['width'], $height, $w, $h);
            }
            $results[] = match ($job['format']) {
                'webp' => imagewebp($scaled, $job['dest'], $quality),
                'avif' => imageavif($scaled, $job['dest'], $quality, 6), // speed 6: eager generation must stay fast
                default => false,
            };
            unset($scaled);
        }

        return $results;
    }

    public function writeEdited(string $src, string $dest, int $quarterTurns, bool $mirror, ?array $crop, string $format, int $quality, int $maxLongSide = 0): bool
    {
        $image = @imagecreatefromstring((string) file_get_contents($src));
        if ($image === false) {
            return false;
        }
        $image = self::upright($image, Orientation::of($src));
        if ($mirror) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        $turns = (($quarterTurns % 4) + 4) % 4;
        if ($turns > 0) {
            $image = imagerotate($image, -90 * $turns, 0) ?: $image; // imagerotate turns counterclockwise
        }
        $region = $crop ?? ['x' => 0, 'y' => 0, 'w' => imagesx($image), 'h' => imagesy($image)];
        $scale = $maxLongSide > 0 ? min(1.0, $maxLongSide / max($region['w'], $region['h'])) : 1.0;
        $outW = max(1, (int) round($region['w'] * $scale));
        $outH = max(1, (int) round($region['h'] * $scale));

        $out = imagecreatetruecolor($outW, $outH);
        if ($format === 'jpeg') {
            imagefill($out, 0, 0, (int) imagecolorallocate($out, 255, 255, 255)); // JPEG has no alpha
        } else {
            imagealphablending($out, false);
            imagesavealpha($out, true);
        }
        imagecopyresampled($out, $image, 0, 0, $region['x'], $region['y'], $outW, $outH, $region['w'], $region['h']);

        return match ($format) {
            'jpeg' => imagejpeg($out, $dest, $quality),
            'png' => imagepng($out, $dest),
            'webp' => imagewebp($out, $dest, $quality),
            default => false,
        };
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
