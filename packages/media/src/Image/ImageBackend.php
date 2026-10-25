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
     * @param list<array{dest: string, width: int, format: string}> $jobs
     * @return list<bool> success per job
     */
    public function writeVariants(string $src, array $jobs, int $quality): array;
}
