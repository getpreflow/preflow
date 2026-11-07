<?php

declare(strict_types=1);

namespace Preflow\Core\Config;

/**
 * Decodes a JSON file once per process. The parsed array is remembered per path and re-read when
 * the file's mtime, ctime, size or inode change, so an edit is never served stale.
 */
final class ParsedJsonFiles
{
    /** @var array<string, array{0: string, 1: mixed}> */
    private static array $files = [];

    private static int $decodes = 0;

    /** The decoded file (as json_decode(..., true) gives it), or null when missing, unreadable or invalid. */
    public static function read(string $path): mixed
    {
        $stat = @stat($path);
        if ($stat === false || !is_file($path)) {
            unset(self::$files[$path]);

            return null;
        }
        $sig = $stat['mtime'] . ':' . $stat['ctime'] . ':' . $stat['size'] . ':' . $stat['ino'];
        if (isset(self::$files[$path]) && self::$files[$path][0] === $sig) {
            return self::$files[$path][1];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        self::$decodes++;
        $data = json_decode($raw, true);
        self::$files[$path] = [$sig, $data];

        return $data;
    }

    /** How many files were actually decoded so far (for tests). */
    public static function decodeCount(): int
    {
        return self::$decodes;
    }

    public static function reset(): void
    {
        self::$files = [];
        self::$decodes = 0;
    }
}
