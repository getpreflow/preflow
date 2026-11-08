<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * Invalidations that could not reach the tag index (SQLite busy, the file unusable), kept in
 * {releaseDir}/pending-invalidations, one JSON line each ({"at": microtime, "tags": [...]}),
 * until a request or command that can reach the index applies them (Refresher::applyPending()).
 *
 * A failed invalidation is never dropped, and while one is pending no page of the release is
 * served or stored (PageCacheMiddleware asks exist(), one stat): the pages it should have
 * forgotten can't be told from the others. So an index that stays unreachable after pages were
 * stored turns the cache off instead of serving pages nothing can refresh.
 *
 * Appends and the apply take an exclusive flock on the file. Applied, the file is emptied, not
 * removed, so an append waiting for the lock never writes into a removed file. A file grown past
 * MAX_BYTES is folded into one invalidation of everything at its earliest time.
 */
final class PendingInvalidations
{
    public const string FILE = 'pending-invalidations';

    public const int MAX_BYTES = 65536;

    private readonly string $file;

    public function __construct(string $releaseDir)
    {
        $this->file = rtrim($releaseDir, '/') . '/' . self::FILE;
    }

    /** Whether invalidations are pending in the release folder $releaseDir (one stat). */
    public static function exist(string $releaseDir): bool
    {
        $file = rtrim($releaseDir, '/') . '/' . self::FILE;
        clearstatcache(true, $file);

        return (int) @filesize($file) > 0;
    }

    public function file(): string
    {
        return $this->file;
    }

    /**
     * Keeps an invalidation of $tags at $at for later. False when the file can't be written.
     *
     * @param list<string> $tags
     */
    public function add(array $tags, float $at): bool
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            return false;
        }
        try {
            @flock($handle, LOCK_EX);
            $line = self::line(array_values($tags), $at);
            $size = (int) (fstat($handle)['size'] ?? 0);
            if ($size + strlen($line) > self::MAX_BYTES) {
                // Too many to keep one by one: everything, from the earliest of them.
                rewind($handle);
                $entries = self::parse((string) stream_get_contents($handle));
                $earliest = min([$at, ...array_column($entries, 'at')]);
                ftruncate($handle, 0);
                rewind($handle);
                $line = self::line([TagIndex::EVERYTHING], $earliest);
            } else {
                fseek($handle, 0, SEEK_END);
            }
            $written = fwrite($handle, $line);
            fflush($handle);

            return $written === strlen($line);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Applies what is pending: $apply gets the merged tags and the earliest time; once it
     * returns, the file is emptied. When it throws, everything stays pending and false is
     * returned. True when nothing is pending afterwards.
     *
     * @param \Closure(list<string>, float): void $apply
     */
    public function apply(\Closure $apply): bool
    {
        if (!self::exist(dirname($this->file))) {
            return true;
        }
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            return false;
        }
        try {
            @flock($handle, LOCK_EX);
            $entries = self::parse((string) stream_get_contents($handle));
            if ($entries !== []) {
                $tags = array_values(array_unique(array_merge(...array_column($entries, 'tags'))));
                $apply($tags, min(array_column($entries, 'at')));
            }
            ftruncate($handle, 0);
            fflush($handle);

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Forgets what is pending (the whole cache was cleared). */
    public function clear(): void
    {
        if (is_file($this->file)) {
            @file_put_contents($this->file, '', LOCK_EX);
        }
    }

    /** @param list<string> $tags */
    private static function line(array $tags, float $at): string
    {
        return json_encode(['at' => $at, 'tags' => $tags], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION) . "\n";
    }

    /**
     * The entries of $content. A line that doesn't parse (a write cut short) counts as a change
     * of everything at the time of the entry before it, or now.
     *
     * @return list<array{at: float, tags: list<string>}>
     */
    private static function parse(string $content): array
    {
        $entries = [];
        $last = microtime(true);
        foreach (explode("\n", $content) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $entry = json_decode($line, true);
            if (!is_array($entry) || !is_array($entry['tags'] ?? null) || !is_numeric($entry['at'] ?? null)) {
                $entries[] = ['at' => $last, 'tags' => [TagIndex::EVERYTHING]];
                continue;
            }
            $last = (float) $entry['at'];
            $entries[] = ['at' => $last, 'tags' => array_values(array_map(strval(...), $entry['tags']))];
        }

        return $entries;
    }
}
