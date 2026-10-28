<?php

declare(strict_types=1);

namespace Preflow\Media;

use Preflow\Core\Application;
use Preflow\Core\Debug\DebugCollector;

/**
 * The media settings: config/media.php, or (deprecated) config/folio.php
 * "assets" when media.php is missing. The two are never merged. A private_dir,
 * versions_dir or bin_dir inside public/ or the media directory is refused: those
 * files would be served without the gate.
 */
final readonly class MediaConfig
{
    /** Cleanup is opt-in: off until a site turns it on; binned assets are deleted for good after bin_days. */
    public const array CLEANUP_DEFAULTS = ['enabled' => false, 'min_age_days' => 7, 'bin_days' => 30];

    public const string DEPRECATION = 'config/folio.php "assets" is deprecated; move it to config/media.php';

    private const string MEDIA = 'config/media.php';
    private const string FOLIO = 'config/folio.php assets';

    /**
     * @param list<int>|null                      $widths
     * @param array<string, array<string, mixed>> $fields
     * @param list<string>                        $filters
     * @param array{enabled: bool, min_age_days: int, bin_days: int} $cleanup
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
        /** The ICC profile for CMYK images without one; null: the shipped FOGRA39 (ColorManagement::DEFAULT_CMYK_PROFILE). */
        public ?string $colorProfileCmyk = null,
        /** Ghostscript for PDF previews (used from PdfPreviewRenderer::GHOSTSCRIPT_FLOOR on); null: "gs" on PATH or in the usual places. */
        public ?string $ghostscript = null,
        /** Poppler's pdftocairo, for PDF previews without a safe Ghostscript; null: looked up like gs. Neither: no previews. */
        public ?string $pdftocairo = null,
        /** ffmpeg for video previews (one frame); null: looked up like gs. None: videos keep their tile. */
        public ?string $ffmpeg = null,
        /** ffprobe for a video's length (where the frame is taken); null: looked up like gs. None: the frame at 1 s. */
        public ?string $ffprobe = null,
        /** Deleted assets ({bin_dir}/{id}/), a shared deploy dir like versions_dir; null: a delete removes them for good. */
        public ?string $binDir = null,
        public array $cleanup = self::CLEANUP_DEFAULTS,
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
            colorProfileCmyk: is_string($values['color_profile_cmyk'] ?? null) && $values['color_profile_cmyk'] !== '' ? $values['color_profile_cmyk'] : null,
            ghostscript: is_string($values['ghostscript'] ?? null) && $values['ghostscript'] !== '' ? $values['ghostscript'] : null,
            pdftocairo: is_string($values['pdftocairo'] ?? null) && $values['pdftocairo'] !== '' ? $values['pdftocairo'] : null,
            ffmpeg: is_string($values['ffmpeg'] ?? null) && $values['ffmpeg'] !== '' ? $values['ffmpeg'] : null,
            ffprobe: is_string($values['ffprobe'] ?? null) && $values['ffprobe'] !== '' ? $values['ffprobe'] : null,
            binDir: is_string($values['bin_dir'] ?? null) && $values['bin_dir'] !== '' ? $values['bin_dir'] : null,
            cleanup: self::cleanup($values['cleanup'] ?? null),
        );
        if ($config->colorProfileCmyk !== null && (!is_file($config->colorProfileCmyk) || !is_readable($config->colorProfileCmyk))) {
            throw new \RuntimeException("{$config->key('color_profile_cmyk')} ({$config->colorProfileCmyk}) is not a readable file.");
        }
        $config->checkOutsideServed($app, 'private_dir', $config->privateDir);
        $config->checkOutsideServed($app, 'versions_dir', $config->versionsDir);
        $config->checkOutsideServed($app, 'bin_dir', $config->binDir);

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
            'color_profile_cmyk' => $this->colorProfileCmyk,
            'ghostscript' => $this->ghostscript,
            'pdftocairo' => $this->pdftocairo,
            'ffmpeg' => $this->ffmpeg,
            'ffprobe' => $this->ffprobe,
            'bin_dir' => $this->binDir,
            'cleanup' => $this->cleanup,
        ];
    }

    /**
     * The cleanup settings over their defaults (a value of the wrong type keeps the default).
     *
     * @return array{enabled: bool, min_age_days: int, bin_days: int}
     */
    private static function cleanup(mixed $values): array
    {
        $values = is_array($values) ? $values : [];

        return [
            'enabled' => ($values['enabled'] ?? false) === true,
            'min_age_days' => is_int($values['min_age_days'] ?? null) && $values['min_age_days'] >= 0 ? $values['min_age_days'] : self::CLEANUP_DEFAULTS['min_age_days'],
            'bin_days' => is_int($values['bin_days'] ?? null) && $values['bin_days'] >= 0 ? $values['bin_days'] : self::CLEANUP_DEFAULTS['bin_days'],
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

    /** A directory of gated files (private_dir, versions_dir, bin_dir) must not be served by the web server. */
    private function checkOutsideServed(Application $app, string $name, ?string $dir): void
    {
        if ($dir === null) {
            return;
        }
        $gated = self::normalizePath($dir);
        foreach ([$app->basePath('public'), $this->dir] as $served) {
            $served = self::normalizePath($served);
            if ($gated === $served || str_starts_with($gated, $served . '/') || str_starts_with($served, $gated . '/')) {
                throw new \RuntimeException("{$this->key($name)} ({$dir}) must be outside public/ and the media directory.");
            }
        }
    }

    /** A setting as an error message names it: "config/media.php name" or "config/folio.php assets.name". */
    private function key(string $name): string
    {
        return $this->source === self::MEDIA ? self::MEDIA . ' ' . $name : self::FOLIO . '.' . $name;
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
