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

        // Takeovers are serialised by a lock file, and the expiry is re-read under it, so of two
        // workers that both saw the expired claim only the first one takes it.
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            return false;
        }
        try {
            clearstatcache(true, $path);
            $stored = @file_get_contents($path);
            // Empty or unreadable: the holder is between creating the file and writing its expiry, so it is held.
            if ($stored === false || !ctype_digit(trim($stored)) || (int) $stored > $now) {
                return false;
            }

            return file_put_contents($path, $expiry) !== false;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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
