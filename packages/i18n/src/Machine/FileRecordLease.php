<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * One file per claimed record, named by a hash of collection and id (ids may hold any characters).
 * The file holds the claim's expiry, so a worker that died without releasing blocks the record only until then.
 */
final class FileRecordLease implements RecordLease
{
    private readonly \Closure $now;

    public function __construct(private readonly string $dir, ?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): int => time();
    }

    public function claim(string $collection, string $id, int $ttlSeconds = 600): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create lease directory {$this->dir}.");
        }
        $path = $this->path($collection, $id);
        $now = ($this->now)();
        $expiry = (string) ($now + $ttlSeconds);

        // 'x' fails when the file exists: exactly one caller creates it.
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            fwrite($handle, $expiry);
            fclose($handle);

            return true;
        }

        $stored = @file_get_contents($path);
        if ($stored === false || (int) $stored > $now) {
            // Still held (or released between the two calls: the next attempt gets it).
            return false;
        }

        // Expired: take it over.
        return file_put_contents($path, $expiry) !== false;
    }

    public function release(string $collection, string $id): void
    {
        @unlink($this->path($collection, $id));
    }

    private function path(string $collection, string $id): string
    {
        return rtrim($this->dir, '/') . '/' . sha1($collection . "\0" . $id);
    }
}
