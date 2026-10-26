<?php

declare(strict_types=1);

namespace Preflow\Media;

/** A rendered image stored beside the file: a document's first page (PNG) or an image's edited master. */
final readonly class Preview
{
    public function __construct(
        public string $path,
        public int $width,
        public int $height,
    ) {}

    /** @return array{path: string, width: int, height: int} */
    public function toArray(): array
    {
        return ['path' => $this->path, 'width' => $this->width, 'height' => $this->height];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if (!is_string($data['path'] ?? null) || $data['path'] === '' || !is_int($data['width'] ?? null) || !is_int($data['height'] ?? null)) {
            return null;
        }

        return new self($data['path'], $data['width'], $data['height']);
    }
}
