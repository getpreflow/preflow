<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

/** One config/image.php preset: a box (cover, contain) or a plain width (max). */
final readonly class Format
{
    /** @param list<int> $widths the srcset widths of a box */
    public function __construct(
        public string $name,
        public int $width,
        public ?int $height,
        public string $fit,
        public array $widths = [],
    ) {}

    /** True for cover and contain: the picture is cut or fitted to width x height. */
    public function isBox(): bool
    {
        return $this->fit === 'cover' || $this->fit === 'contain';
    }

    /** Width over height of a box, else null. */
    public function ratio(): ?float
    {
        return $this->isBox() && $this->height !== null && $this->height > 0 ? $this->width / $this->height : null;
    }
}
