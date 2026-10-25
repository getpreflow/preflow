<?php

declare(strict_types=1);

namespace Preflow\Media;

use Preflow\Core\Application;
use Preflow\Core\Debug\DebugCollector;

/**
 * The media settings: config/media.php, or (deprecated) config/folio.php
 * "assets" when media.php is missing. The two are never merged. A private_dir
 * or versions_dir inside public/ or the media directory is refused: those files
 * would be served without the gate.
 */
final readonly class MediaConfig
{
    public const string DEPRECATION = 'config/folio.php "assets" is deprecated; move it to config/media.php';

    private const string MEDIA = 'config/media.php';
    private const string FOLIO = 'config/folio.php assets';

    /**
     * @param list<int>|null                      $widths
     * @param array<string, array<string, mixed>> $fields
     * @param list<string>                        $filters
     */
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
        /** Earlier files of replaced assets ({versions_dir}/{id}/v{n}.{ext}); a shared deploy dir, like private_dir. */
        public ?string $versionsDir = null,
        /** Extra asset fields by name, in the shape of model fields. */
        public array $fields = [],
        /** Names of the select, relation and boolean extra fields a UI offers as filters. */
        public array $filters = [],
    ) {}

    public static function fromApp(Application $app, ?DebugCollector $debug = null): self
    {
        $values = self::read($app->basePath('config/media.php'));
        $source = self::MEDIA;
        if ($values === null) {
            // Only an existing "assets" block is the (deprecated) source; without one, the defaults are media.php's.
            $folio = self::read($app->basePath('config/folio.php'));
            $values = [];
            if (is_array($folio['assets'] ?? null)) {
                $values = $folio['assets'];
                $source = self::FOLIO;
                $debug?->logDeprecation(self::DEPRECATION);
            }
        }

        $fields = self::fields($values['fields'] ?? null, $source);

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
            versionsDir: array_key_exists('versions_dir', $values)
                ? (is_string($values['versions_dir']) && $values['versions_dir'] !== '' ? $values['versions_dir'] : null)
                : $app->basePath('storage/media-versions'),
            fields: $fields,
            filters: self::filters($values['filters'] ?? null, $fields, $source, $debug),
        );
        $config->checkOutsideServed($app, 'private_dir', $config->privateDir);
        $config->checkOutsideServed($app, 'versions_dir', $config->versionsDir);

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
            'versions_dir' => $this->versionsDir,
            'fields' => $this->fields,
            'filters' => $this->filters,
        ];
    }

    /**
     * The extra fields. Each becomes a column of the asset type, so its name must be a plain column
     * name that is none of the asset's own keys.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fields(mixed $fields, string $source): array
    {
        if (!is_array($fields)) {
            return [];
        }
        $reserved = array_map('strtolower', Asset::RESERVED);
        $out = [];
        foreach ($fields as $name => $definition) {
            $name = (string) $name;
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new \RuntimeException("Extra asset field \"{$name}\" in {$source} is not a usable name (letters, digits and underscores, starting with a letter).");
            }
            if (in_array(strtolower($name), $reserved, true)) {
                throw new \RuntimeException("Extra asset field \"{$name}\" in {$source} is the name of an asset key; choose another name.");
            }
            $out[$name] = is_array($definition) ? $definition : ['type' => is_string($definition) ? $definition : 'string'];
        }

        return $out;
    }

    /**
     * The filters: names of declared select, relation or boolean fields (anything else is dropped).
     *
     * @param array<string, array<string, mixed>> $fields
     * @return list<string>
     */
    private static function filters(mixed $filters, array $fields, string $source, ?DebugCollector $debug): array
    {
        $out = [];
        foreach (is_array($filters) ? $filters : [] as $name) {
            if (is_string($name) && in_array($fields[$name]['type'] ?? null, ['select', 'relation', 'boolean'], true) && !in_array($name, $out, true)) {
                $out[] = $name;
            } elseif (!in_array($name, $out, true)) {
                $message = sprintf('%s "filters" entry %s is not a declared select, relation or boolean field and is ignored', $source, is_string($name) ? "\"{$name}\"" : get_debug_type($name));
                $debug !== null ? $debug->logDeprecation($message) : error_log('preflow/media: ' . $message);
            }
        }

        return $out;
    }

    /** A directory of gated files (private_dir, versions_dir) must not be served by the web server. */
    private function checkOutsideServed(Application $app, string $name, ?string $dir): void
    {
        if ($dir === null) {
            return;
        }
        $gated = self::normalizePath($dir);
        foreach ([$app->basePath('public'), $this->dir] as $served) {
            $served = self::normalizePath($served);
            if ($gated === $served || str_starts_with($gated, $served . '/') || str_starts_with($served, $gated . '/')) {
                $key = $this->source === self::MEDIA ? self::MEDIA . ' ' . $name : self::FOLIO . '.' . $name;
                throw new \RuntimeException("{$key} ({$dir}) must be outside public/ and the media directory.");
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
