<?php

declare(strict_types=1);

namespace Preflow\Media;

/** An earlier file of an asset, kept when it was replaced. */
final readonly class AssetVersion
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public int $number,
        public string $path,
        public string $mime,
        public int $size,
        public ?int $width,
        public ?int $height,
        public array $meta,
        public string $createdAt,
        public ?string $createdBy,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'path' => $this->path,
            'mime' => $this->mime,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'meta' => $this->meta,
            'created_at' => $this->createdAt,
            'created_by' => $this->createdBy,
        ];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if (!is_int($data['number'] ?? null) || $data['number'] < 1 || !is_string($data['path'] ?? null)) {
            return null;
        }

        return new self(
            number: $data['number'],
            path: $data['path'],
            mime: (string) ($data['mime'] ?? ''),
            size: (int) ($data['size'] ?? 0),
            width: is_numeric($data['width'] ?? null) ? (int) $data['width'] : null,
            height: is_numeric($data['height'] ?? null) ? (int) $data['height'] : null,
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
            createdAt: (string) ($data['created_at'] ?? ''),
            createdBy: is_string($data['created_by'] ?? null) && $data['created_by'] !== '' ? $data['created_by'] : null,
        );
    }
}
