<?php

declare(strict_types=1);

namespace Preflow\Folio\Assets;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The shared media library. Files live in a public directory served by the web
 * server ({dir}/Y/m/{id}.{ext}); their metadata are records of a code-defined
 * type (key "_asset", table "folio_assets"). The extension always follows the
 * sniffed MIME type, never the client's file name, and only allowed types are
 * stored, so nothing executable can land in the public directory.
 */
final class AssetLibrary
{
    /** Allowed MIME types and the extension each is stored with. */
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/zip' => 'zip',
    ];

    public const string ID_PATTERN = '/^[a-f0-9]{32}$/';

    private ?TypeDefinition $definition = null;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $now;

    /**
     * @param array<string, mixed> $config config/folio.php "assets" (storage, max_bytes, allow_svg, …)
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly Locales $locales,
        private readonly string $dir,
        private readonly string $url,
        private readonly array $config = [],
        private readonly ?VariantGenerator $variants = null,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    public function definition(): TypeDefinition
    {
        if ($this->definition !== null) {
            return $this->definition;
        }
        $fields = [];
        foreach (['path', 'original_name', 'mime', 'size', 'width', 'height', 'duration', 'alt', 'title', 'focal_x', 'focal_y', 'poster', 'variants', 'created_at', 'created_by'] as $name) {
            $fields[$name] = new TypeFieldDefinition($name, 'string', translatable: $name === 'alt');
        }

        return $this->definition = new TypeDefinition(
            key: '_asset',
            table: 'folio_assets',
            storage: (string) ($this->config['storage'] ?? 'json'),
            fields: $fields,
            idField: 'uuid',
            locales: $this->locales,
        );
    }

    /** @throws AssetUploadException */
    public function upload(UploadedFileInterface $file, ?string $by): Asset
    {
        $name = (string) ($file->getClientFilename() ?? 'upload');
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new AssetUploadException("{$name}: the upload failed (error {$file->getError()}).");
        }
        $this->checkSize($name, (int) ($file->getSize() ?? 0));
        $stream = $file->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $this->store($stream->getContents(), $name, $by);
    }

    /**
     * Copy a file on disk into the library (the source stays where it is).
     *
     * @throws AssetUploadException
     */
    public function importFile(string $absPath, string $originalName, ?string $by): Asset
    {
        if (!is_file($absPath) || !is_readable($absPath)) {
            throw new AssetUploadException("{$originalName}: the file does not exist.");
        }
        $this->checkSize($originalName, (int) filesize($absPath));

        return $this->store((string) file_get_contents($absPath), $originalName, $by);
    }

    public function find(string $id): ?Asset
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }
        $record = $this->dm->findIn($this->definition(), $id);

        return $record === null ? null : Asset::fromRecord($record);
    }

    /** @return list<Asset> newest first */
    public function all(): array
    {
        $assets = array_map(Asset::fromRecord(...), array_values($this->dm->queryIn($this->definition())->get()->items()));
        usort($assets, static fn (Asset $a, Asset $b) => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return $assets;
    }

    /**
     * Edit metadata: alt (merged per locale), title, focal_x/focal_y (0–100),
     * poster (an image asset id; anything else clears it).
     *
     * @param array<string, mixed> $meta
     */
    public function update(string $id, array $meta): Asset
    {
        $asset = $this->find($id) ?? throw new \InvalidArgumentException("Unknown asset {$id}.");
        $data = $asset->toData();

        if (is_array($meta['alt'] ?? null)) {
            $alt = $asset->alt;
            foreach ($meta['alt'] as $locale => $text) {
                if (is_string($locale) && $this->locales->has($locale) && is_string($text)) {
                    $alt[$locale] = trim($text);
                }
            }
            $data['alt'] = $alt;
        }
        if (array_key_exists('title', $meta)) {
            $data['title'] = is_string($meta['title']) ? trim($meta['title']) : '';
        }
        foreach (['focal_x', 'focal_y'] as $key) {
            if (array_key_exists($key, $meta) && is_numeric($meta[$key])) {
                $data[$key] = max(0, min(100, (int) round((float) $meta[$key])));
            }
        }
        if (array_key_exists('poster', $meta)) {
            $poster = is_string($meta['poster']) && $meta['poster'] !== $id ? $this->find($meta['poster']) : null;
            $data['poster'] = $poster !== null && $poster->kind() === 'image' ? $poster->id : '';
        }

        return $this->save($data);
    }

    public function delete(string $id): void
    {
        $asset = $this->find($id);
        if ($asset === null) {
            return;
        }
        foreach ([$asset->path, ...array_map(static fn (Variant $v) => $v->path, $asset->variants)] as $path) {
            $file = $this->absolute($path);
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->dm->deleteIn($this->definition(), $id);
    }

    /** Replace an image's variants (e.g. after the configured widths changed). */
    public function regenerate(Asset $asset): Asset
    {
        if ($this->variants === null) {
            return $asset;
        }
        $this->variants->remove($asset, $this->dir);
        $data = $asset->toData();
        $data['variants'] = array_map(static fn (Variant $v) => $v->toArray(), $this->generateVariants($asset));

        return $this->save($data);
    }

    public function url(string $relativePath): string
    {
        return rtrim($this->url, '/') . '/' . ltrim($relativePath, '/');
    }

    public function absolute(string $relativePath): string
    {
        return rtrim($this->dir, '/') . '/' . ltrim($relativePath, '/');
    }

    /** A small preview URL: the 480 (else smallest) WebP variant, else the original image; null for other files. */
    public function thumbnailUrl(Asset $asset): ?string
    {
        if ($asset->kind() !== 'image') {
            return null;
        }
        $webp = array_filter($asset->variants, static fn (Variant $v) => $v->format === 'webp');
        usort($webp, static fn (Variant $a, Variant $b) => [$a->width !== 480, $a->width] <=> [$b->width !== 480, $b->width]);

        return $this->url(($webp[0] ?? null)?->path ?? $asset->path);
    }

    /** True for the id of an existing asset (never for a path). */
    public function isAssetId(string $value): bool
    {
        return $this->find($value) !== null;
    }

    /** @throws AssetUploadException */
    private function store(string $bytes, string $name, ?string $by): Asset
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if ($mime === 'image/svg+xml' && ($this->config['allow_svg'] ?? false) !== true) {
            throw new AssetUploadException('SVG uploads are disabled.');
        }
        $ext = self::EXTENSIONS[$mime] ?? null;
        if ($ext === null) {
            throw new AssetUploadException("{$name}: files of type {$mime} are not allowed.");
        }

        $now = ($this->now)();
        $id = bin2hex(random_bytes(16));
        $path = $now->format('Y/m') . '/' . $id . '.' . $ext;
        $file = $this->absolute($path);
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
            throw new AssetUploadException('The media directory is not writable.');
        }
        file_put_contents($file, $bytes);

        $width = $height = null;
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            $size = @getimagesize($file);
            if (is_array($size)) {
                [$width, $height] = [(int) $size[0], (int) $size[1]];
            }
        }

        $asset = new Asset(
            id: $id,
            path: $path,
            originalName: mb_substr(basename(str_replace('\\', '/', $name)), 0, 255),
            mime: $mime,
            size: strlen($bytes),
            width: $width,
            height: $height,
            duration: null,
            alt: [],
            title: '',
            focalX: 50,
            focalY: 50,
            posterId: null,
            variants: [],
            createdAt: $now->format(DATE_ATOM),
            createdBy: $by,
        );
        $data = $asset->toData();
        $data['variants'] = array_map(static fn (Variant $v) => $v->toArray(), $this->generateVariants($asset));

        return $this->save($data);
    }

    /** @return list<Variant> */
    private function generateVariants(Asset $asset): array
    {
        if ($this->variants === null || $asset->width === null) {
            return [];
        }

        return $this->variants->generate($this->dir, $asset->path, $asset->mime, $asset->width);
    }

    /** @param array<string, mixed> $data */
    private function save(array $data): Asset
    {
        $record = DynamicRecord::fromArray($this->definition(), $data);
        $this->dm->saveType($record, false);

        return Asset::fromRecord($record);
    }

    /** @throws AssetUploadException */
    private function checkSize(string $name, int $bytes): void
    {
        $limit = (int) ($this->config['max_bytes'] ?? 20 * 1024 * 1024);
        if ($bytes > $limit) {
            throw new AssetUploadException("{$name} is larger than the " . self::humanBytes($limit) . ' limit.');
        }
    }

    private static function humanBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1) . ' MB',
            $bytes >= 1024 => round($bytes / 1024) . ' KB',
            default => $bytes . ' B',
        };
    }
}
