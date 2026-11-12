<?php

declare(strict_types=1);

namespace Preflow\Core\Composer;

/**
 * What Preflow reads from Composer's vendor/composer/installed.json, decoded at most once per
 * change. The file is large and every request used to decode it (twice); here the small subset
 * the consumers need is cached as a PHP file (OPcache-friendly) keyed by path, mtime and size,
 * so a `composer install` or `update` shows up on the next request. The path is installed.json's
 * relative to the cache folder, so the cache moves with the project like the other caches (a
 * release folder, a test's copy of the project) and never holds the project's absolute path.
 *
 * Each package comes back as:
 *   name          string
 *   install-path  string|null
 *   extra         array (the package's composer `extra` object as Composer has it, only when it is a
 *                 non-empty array); consumers read their own keys through extra()
 */
final class InstalledPackages
{
    /** Part of the cache key: a cache written by an older reader (fewer keys kept) is a miss. */
    private const FORMAT = 3;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $memo = [];

    /** @return list<array{name: string, install-path: string|null, extra?: array<string, mixed>}> */
    public static function read(string $installedJson, ?string $cacheDir = null): array
    {
        clearstatcache(true, $installedJson);
        $mtime = @filemtime($installedJson);
        $size = @filesize($installedJson);
        if ($mtime === false || $size === false) {
            return [];
        }
        $key = $installedJson . '|' . $mtime . '|' . $size . '|v' . self::FORMAT;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $file = null;
        $stored = '';
        if ($cacheDir !== null) {
            $cacheDir = rtrim($cacheDir, '/');
            $relative = self::relativePath($installedJson, $cacheDir);
            $file = $cacheDir . '/installed-' . hash('xxh3', $relative) . '.php';
            $stored = $relative . '|' . $mtime . '|' . $size . '|v' . self::FORMAT;
        }
        if ($file !== null && is_file($file)) {
            try {
                $hit = @include $file;
            } catch (\Throwable) {
                $hit = null; // a corrupt cache file is a miss, never a fatal
            }
            if (is_array($hit) && ($hit['key'] ?? null) === $stored && is_array($hit['packages'] ?? null)) {
                return self::$memo[$key] = $hit['packages'];
            }
        }

        $packages = self::parse($installedJson);
        if ($file !== null) {
            self::store($file, $stored, $packages);
        }
        self::$memo = [$key => $packages];

        return $packages;
    }

    /**
     * A value of a package's `extra` by dotted path ('folio.blocks', 'preflow.translations'),
     * null when the package does not declare it.
     *
     * @param array<string, mixed> $package one entry of read()
     */
    public static function extra(array $package, string $path): mixed
    {
        $value = $package['extra'] ?? null;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * $path as seen from $dir ("../../../vendor/composer/installed.json" from storage/cache/composer),
     * by the paths as given (no realpath(): a linked vendor/ stays where the project has it).
     */
    private static function relativePath(string $path, string $dir): string
    {
        $from = array_values(array_filter(explode('/', $dir), static fn (string $part): bool => $part !== '' && $part !== '.'));
        $to = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== '' && $part !== '.'));
        $common = 0;
        while ($common < count($from) && $common < count($to) - 1 && $from[$common] === $to[$common]) {
            $common++;
        }

        return str_repeat('../', count($from) - $common) . implode('/', array_slice($to, $common));
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
            if (is_array($package['extra'] ?? null) && $package['extra'] !== []) {
                $slim['extra'] = $package['extra'];
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
