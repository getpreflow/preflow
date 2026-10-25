<?php

declare(strict_types=1);

namespace Preflow\Media;

use Preflow\Core\Application;
use Preflow\Core\Debug\DebugCollector;

/**
 * The media settings: config/media.php, or (deprecated) config/folio.php
 * "assets" when media.php is missing. The two are never merged. A private_dir
 * inside public/ or the media directory is refused: those files would be
 * served without the gate.
 */
final readonly class MediaConfig
{
    public const string DEPRECATION = 'config/folio.php "assets" is deprecated; move it to config/media.php';

    private const string MEDIA = 'config/media.php';
    private const string FOLIO = 'config/folio.php assets';

    /** @param list<int>|null $widths */
    public function __construct(
        public string $dir,
        public string $url = '/media',
        public string $storage = 'json',
        public int $maxBytes = 20 * 1024 * 1024,
        public int $maxPixels = 30_000_000,
        public bool $warmOnUpload = false,
        public bool $allowSvg = false,
        public ?array $widths = null,
        public int $quality = 75,
        public ?string $privateDir = null,
        public string $privateUrl = '/files',
        private string $source = self::MEDIA,
    ) {}

    public static function fromApp(Application $app, ?DebugCollector $debug = null): self
    {
        $values = self::read($app->basePath('config/media.php'));
        $source = self::MEDIA;
        if ($values === null) {
            $folio = self::read($app->basePath('config/folio.php'));
            $values = is_array($folio['assets'] ?? null) ? $folio['assets'] : [];
            $source = self::FOLIO;
            if (isset($folio['assets'])) {
                $debug?->logDeprecation(self::DEPRECATION);
            }
        }

        $config = new self(
            dir: is_string($values['dir'] ?? null) ? $values['dir'] : $app->basePath('public/media'),
            url: is_string($values['url'] ?? null) ? $values['url'] : '/media',
            storage: is_string($values['storage'] ?? null) && $values['storage'] !== '' ? $values['storage'] : 'json',
            maxBytes: (int) ($values['max_bytes'] ?? 20 * 1024 * 1024),
            maxPixels: (int) ($values['max_pixels'] ?? 30_000_000),
            warmOnUpload: ($values['warm_on_upload'] ?? false) === true,
            allowSvg: ($values['allow_svg'] ?? false) === true,
            widths: is_array($values['widths'] ?? null) ? array_values(array_map('intval', $values['widths'])) : null,
            quality: (int) ($values['quality'] ?? 75),
            privateDir: array_key_exists('private_dir', $values)
                ? (is_string($values['private_dir']) && $values['private_dir'] !== '' ? $values['private_dir'] : null)
                : $app->basePath('storage/private-media'),
            privateUrl: is_string($values['private_url'] ?? null) ? $values['private_url'] : '/files',
            source: $source,
        );
        $config->checkPrivateDir($app);

        return $config;
    }

    /** Where the values came from: "config/media.php" or "config/folio.php assets". */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * The settings as MediaLibrary's config array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dir' => $this->dir,
            'url' => $this->url,
            'storage' => $this->storage,
            'max_bytes' => $this->maxBytes,
            'max_pixels' => $this->maxPixels,
            'warm_on_upload' => $this->warmOnUpload,
            'allow_svg' => $this->allowSvg,
            'widths' => $this->widths,
            'quality' => $this->quality,
            'private_dir' => $this->privateDir,
            'private_url' => $this->privateUrl,
        ];
    }

    private function checkPrivateDir(Application $app): void
    {
        if ($this->privateDir === null) {
            return;
        }
        $private = self::normalizePath($this->privateDir);
        foreach ([$app->basePath('public'), $this->dir] as $served) {
            $served = self::normalizePath($served);
            if ($private === $served || str_starts_with($private, $served . '/') || str_starts_with($served, $private . '/')) {
                $key = $this->source === self::MEDIA ? self::MEDIA . ' private_dir' : self::FOLIO . '.private_dir';
                throw new \RuntimeException("{$key} ({$this->privateDir}) must be outside public/ and the media directory.");
            }
        }
    }

    /** @return array<string, mixed>|null */
    private static function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $values = require $file;

        return is_array($values) ? $values : [];
    }

    /** An absolute path with symlinks resolved as far as it exists, so a link cannot hide the private dir inside public/. */
    private static function normalizePath(string $path): string
    {
        $path = rtrim($path, '/');
        $suffix = '';
        while ($path !== '' && $path !== '/' && $path !== '.' && realpath($path) === false) {
            $suffix = '/' . basename($path) . $suffix;
            $path = dirname($path);
        }

        return rtrim((string) realpath($path === '' ? '/' : $path), '/') . $suffix;
    }
}
