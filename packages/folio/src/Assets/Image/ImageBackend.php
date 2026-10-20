<?php

declare(strict_types=1);

namespace Preflow\Folio\Assets\Image;

interface ImageBackend
{
    /** Whether the backend can write the format ("webp" or "avif"). */
    public function supports(string $format): bool;

    /** Write $src scaled to $width (height keeps the ratio) as $format to $dest. */
    public function resize(string $src, string $dest, int $width, string $format, int $quality): bool;
}
