<?php

declare(strict_types=1);

namespace Preflow\Folio\Assets;

/** A generated, resized copy of an image asset, stored next to the original. */
final readonly class Variant
{
    public function __construct(
        public int $width,
        public string $format,
        public string $path,
    ) {}

    /** @return array{width: int, format: string, path: string} */
    public function toArray(): array
    {
        return ['width' => $this->width, 'format' => $this->format, 'path' => $this->path];
    }
}
