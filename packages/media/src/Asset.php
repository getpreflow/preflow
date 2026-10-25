<?php

declare(strict_types=1);

namespace Preflow\Media;

use Preflow\Data\DynamicRecord;

final readonly class Asset
{
    /**
     * @param array<string, string> $alt      alt text per locale
     * @param list<Variant>         $variants
     * @param list<AssetVersion>    $versions earlier files, kept when the asset was replaced
     * @param list<string>          $palette  5-8 hex colours, most significant first
     * @param array<string, mixed>  $extra    values of the configured extra fields
     */
    public function __construct(
        public string $id,
        public string $path,
        public string $originalName,
        public string $mime,
        public int $size,
        public ?int $width,
        public ?int $height,
        public ?float $duration,
        public array $alt,
        public string $title,
        public int $focalX,
        public int $focalY,
        public ?string $posterId,
        public array $variants,
        public string $createdAt,
        public ?string $createdBy,
        /** Stored in assets.private_dir and served only through the gated private URL. */
        public bool $private = false,
        /** The number of the current file; 1 keeps the plain file names. */
        public int $version = 1,
        public array $versions = [],
        public ?Preview $preview = null,
        public ?string $color = null,
        public array $palette = [],
        public ?string $textColor = null,
        public array $extra = [],
        public ?string $note = null,
    ) {}

    public function kind(): string
    {
        return match (true) {
            str_starts_with($this->mime, 'image/') => 'image',
            str_starts_with($this->mime, 'video/') => 'video',
            default => 'document',
        };
    }

    /** The alt text in a locale, else in the default locale, else ''. */
    public function altFor(string $locale, string $default): string
    {
        $text = $this->alt[$locale] ?? '';

        return $text !== '' ? $text : ($this->alt[$default] ?? '');
    }

    /** The title, else the original file name. */
    public function label(): string
    {
        return $this->title !== '' ? $this->title : $this->originalName;
    }

    public static function fromRecord(DynamicRecord $record): self
    {
        $data = $record->toArray();
        $alt = self::decode($data['alt'] ?? []);
        $variants = [];
        foreach (self::decode($data['variants'] ?? []) as $v) {
            if (is_array($v) && is_int($v['width'] ?? null) && is_string($v['format'] ?? null) && is_string($v['path'] ?? null)) {
                $variants[] = new Variant($v['width'], $v['format'], $v['path']);
            }
        }

        $versions = [];
        foreach (self::decode($data['versions'] ?? []) as $v) {
            $version = is_array($v) ? AssetVersion::fromArray($v) : null;
            if ($version !== null) {
                $versions[] = $version;
            }
        }
        $palette = array_values(array_filter(self::decode($data['palette'] ?? []), static fn ($c) => is_string($c) && $c !== ''));

        return new self(
            id: (string) $record->getId(),
            path: (string) ($data['path'] ?? ''),
            originalName: (string) ($data['original_name'] ?? ''),
            mime: (string) ($data['mime'] ?? ''),
            size: (int) ($data['size'] ?? 0),
            width: isset($data['width']) && is_numeric($data['width']) ? (int) $data['width'] : null,
            height: isset($data['height']) && is_numeric($data['height']) ? (int) $data['height'] : null,
            duration: isset($data['duration']) && is_numeric($data['duration']) ? (float) $data['duration'] : null,
            alt: array_filter($alt, static fn ($v, $k) => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH),
            title: (string) ($data['title'] ?? ''),
            focalX: (int) ($data['focal_x'] ?? 50),
            focalY: (int) ($data['focal_y'] ?? 50),
            posterId: is_string($data['poster'] ?? null) && $data['poster'] !== '' ? $data['poster'] : null,
            variants: $variants,
            createdAt: (string) ($data['created_at'] ?? ''),
            createdBy: is_string($data['created_by'] ?? null) && $data['created_by'] !== '' ? $data['created_by'] : null,
            // Records stored before the flag existed are public.
            private: in_array($data['private'] ?? null, [true, 1, '1'], true),
            // Records stored before versions existed have only their first file.
            version: isset($data['version']) && is_numeric($data['version']) && (int) $data['version'] >= 1 ? (int) $data['version'] : 1,
            versions: $versions,
            preview: Preview::fromArray(self::decode($data['preview'] ?? [])),
            color: self::text($data['color'] ?? null),
            palette: $palette,
            textColor: self::text($data['text_color'] ?? null),
            extra: self::decode($data['extra'] ?? []),
            note: self::text($data['note'] ?? null),
        );
    }

    /** @return array<string, mixed> storage data (SQL drivers get lists and maps as JSON text) */
    public function toData(): array
    {
        return [
            'uuid' => $this->id,
            'path' => $this->path,
            'original_name' => $this->originalName,
            'mime' => $this->mime,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration,
            'alt' => $this->alt,
            'title' => $this->title,
            'focal_x' => $this->focalX,
            'focal_y' => $this->focalY,
            'poster' => $this->posterId ?? '',
            'variants' => array_map(static fn (Variant $v) => $v->toArray(), $this->variants),
            'created_at' => $this->createdAt,
            'created_by' => $this->createdBy ?? '',
            'private' => $this->private ? 1 : 0,
            'version' => $this->version,
            'versions' => array_map(static fn (AssetVersion $v) => $v->toArray(), $this->versions),
            'preview' => $this->preview?->toArray() ?? [],
            'color' => $this->color ?? '',
            'palette' => $this->palette,
            'text_color' => $this->textColor ?? '',
            'extra' => $this->extra,
            'note' => $this->note ?? '',
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<mixed> */
    private static function decode(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
