<?php

declare(strict_types=1);

namespace Preflow\Media;

use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;

final readonly class Asset
{
    /** The asset's own columns that hold text (JSON text for lists and maps). */
    public const array TEXT_COLUMNS = ['path', 'original_name', 'mime', 'size', 'width', 'height', 'duration', 'alt', 'title', 'focal_x', 'focal_y', 'poster', 'variants', 'created_at', 'created_by', 'version', 'versions', 'preview', 'color', 'palette', 'text_color', 'note', 'rotation', 'flip', 'crop', 'crops', 'master', 'derived_from', 'maker', 'maker_params'];

    /** Every key of the asset's own record: an extra field may not use one of these names. */
    public const array RESERVED = [...self::TEXT_COLUMNS, 'private', 'derived_version', 'uuid'];

    /**
     * @param array<string, string> $alt      alt text per locale
     * @param list<Variant>         $variants
     * @param list<AssetVersion>    $versions earlier files, kept when the asset was replaced
     * @param list<string>          $palette  5-8 hex colours, most significant first
     * @param array<string, CropRect> $crops  a crop per box preset, in percent of the rotated and flipped original
     * @param array<string, mixed>  $extra    values of the configured extra fields (each is a column of its own), by field name
     * @param array<string, mixed>  $makerParams the settings the copy's maker made it with (e.g. a copyright line)
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
        /** Quarter turns clockwise, 0-3 (column rotation). */
        public int $rotation = 0,
        /** Mirrored left-right (column flip: 'h' or ''). */
        public bool $mirrored = false,
        public ?CropRect $crop = null,
        public array $crops = [],
        /** The edited image (rotated, flipped, cropped) the variants are made from. */
        public ?Preview $master = null,
        /** The id of the original this asset is a copy of (always an original, never a copy); null for an original. */
        public ?string $derivedFrom = null,
        /** How the copy was made: a maker's name ("copy" for a plain copy), or null when nobody said. */
        public ?string $maker = null,
        public array $makerParams = [],
        /** The original's file version the copy was made from. */
        public ?int $derivedVersion = null,
    ) {}

    /** True for a copy of another asset (derived_from is set). */
    public function isCopy(): bool
    {
        return $this->derivedFrom !== null;
    }

    /** True when the delivered image differs from the file: turned, mirrored or cropped. */
    public function isEdited(): bool
    {
        return $this->rotation !== 0 || $this->mirrored || $this->crop !== null;
    }

    public function kind(): string
    {
        return self::kindOf($this->mime);
    }

    /** "image", "video" or "document" for a MIME type. */
    public static function kindOf(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
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
        $crops = [];
        foreach (self::decode($data['crops'] ?? []) as $preset => $rect) {
            $rect = CropRect::fromArray($rect);
            if (is_string($preset) && $preset !== '' && $rect !== null) {
                $crops[$preset] = $rect;
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
            extra: self::extraFrom($record->getType(), $data),
            note: self::text($data['note'] ?? null),
            rotation: is_numeric($data['rotation'] ?? null) ? ((int) $data['rotation'] % 4 + 4) % 4 : 0,
            mirrored: ($data['flip'] ?? null) === 'h',
            crop: CropRect::fromArray(self::decode($data['crop'] ?? [])),
            crops: $crops,
            master: Preview::fromArray(self::decode($data['master'] ?? [])),
            derivedFrom: self::text($data['derived_from'] ?? null),
            maker: self::text($data['maker'] ?? null),
            makerParams: array_filter(self::decode($data['maker_params'] ?? []), static fn ($k) => is_string($k), ARRAY_FILTER_USE_KEY),
            derivedVersion: is_numeric($data['derived_version'] ?? null) && (int) $data['derived_version'] >= 1 ? (int) $data['derived_version'] : null,
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
            'note' => $this->note ?? '',
            'rotation' => $this->rotation,
            'flip' => $this->mirrored ? 'h' : '',
            'crop' => $this->crop?->toArray() ?? [],
            'crops' => array_map(static fn (CropRect $c) => $c->toArray(), $this->crops),
            'master' => $this->master?->toArray() ?? [],
            'derived_from' => $this->derivedFrom ?? '',
            'maker' => $this->maker ?? '',
            'maker_params' => $this->makerParams,
            'derived_version' => $this->derivedVersion,
        ] + array_diff_key($this->extra, array_flip(self::RESERVED));
    }

    /**
     * The values of the type's extra fields (every field that is not one of the asset's own keys),
     * in the shape of their type: a boolean, a list of ids for a multiple relation, else text.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function extraFrom(TypeDefinition $type, array $data): array
    {
        $extra = [];
        foreach ($type->fields as $name => $field) {
            if (in_array($name, self::RESERVED, true)) {
                continue;
            }
            $value = $data[$name] ?? null;
            if ($field->type === 'boolean') {
                $extra[$name] = in_array($value, [true, 1, '1'], true);
            } elseif ($field->type === 'relation' && ($field->config['relation']['multiple'] ?? false) === true) {
                $extra[$name] = array_values(array_filter(
                    array_map(static fn ($id) => is_string($id) || is_int($id) ? (string) $id : '', is_string($value) && $value !== '' && !str_starts_with($value, '[') ? [$value] : self::decode($value)),
                    static fn (string $id) => $id !== '',
                ));
            } else {
                $extra[$name] = is_scalar($value) ? $value : '';
            }
        }

        return $extra;
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
