<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

interface ImageBackend
{
    /** Whether the backend can write the format ("webp" or "avif"). */
    public function supports(string $format): bool;

    /**
     * Decode $src once (upright, per its EXIF orientation) and write each job:
     * scaled to its width (height keeps the ratio) in its format.
     *
     * A job may carry a crop (pixels of the upright source): that region is scaled to
     * the width, with height round(width x h / w).
     *
     * @param list<array{dest: string, width: int, format: string, crop?: array{x: int, y: int, w: int, h: int}}> $jobs
     * @return list<bool> success per job
     */
    public function writeVariants(string $src, array $jobs, int $quality): array;

    /**
     * Write $src upright, mirrored when $mirror, turned clockwise by $quarterTurns, then cut
     * to $crop (pixels of the turned image), scaled down so its long side is at most
     * $maxLongSide (when above 0). $format: jpeg, png or webp; the output is sRGB and stripped.
     *
     * @param ?array{x: int, y: int, w: int, h: int} $crop
     */
    public function writeEdited(string $src, string $dest, int $quarterTurns, bool $mirror, ?array $crop, string $format, int $quality, int $maxLongSide = 0): bool;
}
