<?php

declare(strict_types=1);

namespace Preflow\Core\Composer;

/**
 * What Preflow reads from Composer's vendor/composer/installed.json, decoded at most once per
 * change. The file is large and every request used to decode it (twice); here the small subset
 * the consumers need is cached as a PHP file (OPcache-friendly) keyed by path, mtime and size,
 * so a `composer install` or `update` shows up on the next request.
 *
 * Each package comes back as:
 *   name          string
 *   install-path  string|null
 *   translations  mixed (extra.preflow.translations, only when declared)
 *   extension     mixed (extra.folio.extension, only when declared)
 */
final class InstalledPackages
{
    /** @var array<string, list<array<string, mixed>>> */
    private static array $memo = [];

    /** @return list<array{name: string, install-path: string|null, translations?: mixed, extension?: mixed}> */
    public static function read(string $installedJson, ?string $cacheDir = null): array
    {
        clearstatcache(true, $installedJson);
        $mtime = @filemtime($installedJson);
        $size = @filesize($installedJson);
        if ($mtime === false || $size === false) {
            return [];
        }
        $key = $installedJson . '|' . $mtime . '|' . $size;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $file = $cacheDir !== null ? rtrim($cacheDir, '/') . '/installed-' . hash('xxh3', $installedJson) . '.php' : null;
        if ($file !== null && is_file($file)) {
            try {
                $hit = @include $file;
            } catch (\Throwable) {
                $hit = null; // a corrupt cache file is a miss, never a fatal
            }
            if (is_array($hit) && ($hit['key'] ?? null) === $key && is_array($hit['packages'] ?? null)) {
                return self::$memo[$key] = $hit['packages'];
            }
        }

        $packages = self::parse($installedJson);
        if ($file !== null) {
            self::store($file, $key, $packages);
        }
        self::$memo = [$key => $packages];

        return $packages;
    }

    /** @return list<array<string, mixed>> */
    private static function parse(string $installedJson): array
    {
        $data = json_decode((string) @file_get_contents($installedJson), true);
        $raw = is_array($data) ? ($data['packages'] ?? $data) : [];

        $packages = [];
        foreach (is_array($raw) ? $raw : [] as $package) {
            if (!is_array($package)) {
                continue;
            }
            $slim = [
                'name' => (string) ($package['name'] ?? ''),
                'install-path' => is_string($package['install-path'] ?? null) ? $package['install-path'] : null,
            ];
            if (isset($package['extra']['preflow']['translations'])) {
                $slim['translations'] = $package['extra']['preflow']['translations'];
            }
            if (isset($package['extra']['folio']['extension'])) {
                $slim['extension'] = $package['extra']['folio']['extension'];
            }
            $packages[] = $slim;
        }

        return $packages;
    }

    /** @param list<array<string, mixed>> $packages */
    private static function store(string $file, string $key, array $packages): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $code = '<?php return ' . var_export(['key' => $key, 'packages' => $packages], true) . ';';
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $code) === strlen($code) && @rename($tmp, $file)) {
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }

            return;
        }
        @unlink($tmp);
    }
}
