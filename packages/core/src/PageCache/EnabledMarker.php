<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * storage/cache/pages/enabled: written once the switched-on cache has cleared what it held
 * before, removed by the first write the data layer reports while the cache is off. Writes
 * made while the cache was off were never seen, so pages stored before it was switched off
 * may show an old state: the first request after switching it on again finds no marker and
 * clears the store before serving anything.
 */
final class EnabledMarker
{
    public const string FILE = 'enabled';

    private readonly string $file;

    /** @param string $root storage/cache/pages (absolute) */
    public function __construct(string $root)
    {
        $this->file = rtrim($root, '/') . '/' . self::FILE;
    }

    public function exists(): bool
    {
        return is_file($this->file);
    }

    /** Writes the marker; false when it can't be written. */
    public function write(): bool
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        return @file_put_contents($this->file, date(DATE_ATOM) . "\n") !== false;
    }

    public function remove(): void
    {
        @unlink($this->file);
    }
}
