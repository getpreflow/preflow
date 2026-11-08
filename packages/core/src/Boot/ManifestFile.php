<?php

declare(strict_types=1);

namespace Preflow\Core\Boot;

/**
 * The boot manifest file, {basePath}/bootstrap/cache/boot.php: per release (not in storage/,
 * which a site may share between releases), written atomically, git-ignored and removed by
 * cache:clear.
 *
 * Its array:
 *   version   int, VERSION
 *   built     string, when it was written (ISO 8601)
 *   sources   relative path => signature of every file or directory a value was made from
 *             (`preflow optimize --check` compares them)
 *   runtime   the sources checked on every request: '.env' and 'config/folio/extensions.json'
 *             => signature, plus 'env' (the .env keys and a hash of their effective values)
 *   config    config file name => its result
 *   packages  the package discovery data (InstalledPackages)
 *   catalogs  key => value, as producers remember them
 *   routes    compiled route data, or null
 *
 * load() uses it only while the runtime-checked sources are unchanged: an edited .env, a rewritten
 * extensions.json or a different environment value means a live boot for that request.
 */
final class ManifestFile
{
    public const int VERSION = 1;

    public const string PATH = 'bootstrap/cache/boot.php';

    /** Sources that change without a deploy: checked on every request. */
    public const array RUNTIME = ['.env', 'config/folio/extensions.json'];

    public static function path(string $basePath): string
    {
        return rtrim($basePath, '/') . '/' . self::PATH;
    }

    /**
     * The manifest's array when one exists, is readable and current: the right version, the
     * runtime-checked sources unchanged and the .env keys' effective values as they were.
     * Null otherwise (a live boot).
     *
     * @return array<string, mixed>|null
     */
    public static function load(string $basePath): ?array
    {
        $file = self::path($basePath);
        if (!is_file($file)) {
            return null;
        }
        try {
            $data = include $file;
        } catch (\Throwable) {
            return null; // a corrupt manifest is no manifest
        }
        if (!is_array($data) || ($data['version'] ?? null) !== self::VERSION || !is_array($data['runtime'] ?? null)) {
            return null;
        }
        $base = rtrim($basePath, '/');
        foreach (self::RUNTIME as $source) {
            if (($data['runtime'][$source] ?? null) !== self::stamp($base . '/' . $source)) {
                return null;
            }
        }
        $env = $data['runtime']['env'] ?? null;
        if (!is_array($env) || !is_array($env['keys'] ?? null) || ($env['hash'] ?? null) !== self::envHash($env['keys'])) {
            return null;
        }

        return $data;
    }

    /**
     * Writes the manifest atomically (temporary file, checked by loading it back, then renamed).
     *
     * @param array<string, mixed> $data
     */
    public static function write(string $basePath, array $data): void
    {
        $file = self::path($basePath);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create {$dir}.");
        }
        $code = "<?php\n\n// Written by `preflow optimize`; do not edit. `preflow cache:clear` removes it.\n\nreturn " . var_export($data, true) . ";\n";
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $code) !== strlen($code)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not write {$tmp}.");
        }
        try {
            $back = include $tmp;
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw new \RuntimeException('The manifest does not load back: ' . $e->getMessage(), 0, $e);
        }
        if (serialize($back) !== serialize($data)) {
            @unlink($tmp);
            throw new \RuntimeException('The manifest does not load back as written (a value is not exportable).');
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not move the manifest to {$file}.");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /** Removes the manifest (if any): the next web request boots live. */
    public static function forget(string $basePath): bool
    {
        $file = self::path($basePath);
        if (!is_file($file)) {
            return false;
        }
        $removed = @unlink($file);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        return $removed;
    }

    /**
     * A runtime-checked file's stamp: mtime, size and inode ("-" when missing). Cheap enough
     * for every request: one stat.
     */
    public static function stamp(string $path): string
    {
        $stat = @stat($path);

        return $stat === false ? '-' : $stat['mtime'] . ':' . $stat['size'] . ':' . $stat['ino'];
    }

    /**
     * A source's signature for `optimize --check`: a file's mtime and size, a directory's sorted
     * list of files (relative path, mtime, size) hashed, "-" when missing.
     */
    public static function signature(string $path): string
    {
        clearstatcache(true, $path);
        if (is_file($path)) {
            return 'f:' . filemtime($path) . ':' . filesize($path);
        }
        if (!is_dir($path)) {
            return '-';
        }
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $entries[] = substr($file->getPathname(), strlen($path) + 1) . '@' . $file->getMTime() . ':' . $file->getSize();
            }
        }
        sort($entries);

        return 'd:' . count($entries) . ':' . hash('xxh3', implode('|', $entries));
    }

    /**
     * The variable names a .env file sets (as EnvLoader reads it).
     *
     * @return list<string>
     */
    public static function envKeys(string $envFile): array
    {
        $lines = is_file($envFile) ? file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        $keys = [];
        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            $keys[] = trim(substr($line, 0, (int) strpos($line, '=')));
        }

        return array_values(array_unique($keys));
    }

    /**
     * A hash of the effective values (getenv()) of $keys: a value the web server's environment
     * sets differently than the shell that ran `optimize` (EnvLoader never overrides one) makes
     * the manifest unusable instead of wrong.
     *
     * @param list<string> $keys
     */
    public static function envHash(array $keys): string
    {
        $values = [];
        foreach ($keys as $key) {
            $value = getenv((string) $key);
            $values[] = $key . '=' . ($value === false ? "\0" : $value);
        }

        return hash('sha256', implode("\n", $values));
    }
}
