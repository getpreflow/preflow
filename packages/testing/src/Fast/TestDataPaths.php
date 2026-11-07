<?php

declare(strict_types=1);

namespace Preflow\Testing\Fast;

/**
 * Points a test's JSON storage at its own temp root: DATA_PATH for the records and
 * DATA_CACHE_PATH for their query caches (`{root}/data` and `{root}/cache/data`).
 *
 * Without the cache path the caches of every test pile up in the site's shared
 * storage/cache/data, one set per random DATA_PATH, and are never removed.
 */
final class TestDataPaths
{
    public const array VARIABLES = ['DATA_PATH', 'DATA_CACHE_PATH'];

    /** Make both folders under $root and set the environment variables; returns [data path, cache path]. */
    public static function use(string $root): array
    {
        $root = rtrim($root, '/');
        $paths = [$root . '/data', $root . '/cache/data'];
        foreach ($paths as $path) {
            if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
                throw new \RuntimeException("Cannot create test directory {$path}");
            }
        }
        putenv('DATA_PATH=' . $paths[0]);
        putenv('DATA_CACHE_PATH=' . $paths[1]);
        $_ENV['DATA_PATH'] = $paths[0];
        $_ENV['DATA_CACHE_PATH'] = $paths[1];

        return $paths;
    }

    /** Unset both variables again (call it where the scaffold removes its temp root). */
    public static function release(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
    }
}
