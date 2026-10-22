<?php

declare(strict_types=1);

namespace Preflow\Auth\Support;

/**
 * An exclusive file lock for storage without atomic SQL (the JSON driver),
 * used by Tokens and RememberMe. Internal to preflow/auth.
 */
final class FileLock
{
    /**
     * Runs $fn holding an exclusive lock on {$name}.lock in $dir. $fn returns
     * [result, gone]; the lock file is removed (best effort) only when gone is
     * true, i.e. the guarded row no longer exists. A lock taken on a file that
     * was removed meanwhile is retried on the current file, so two holders never
     * overlap.
     *
     * @template T
     * @param \Closure(): array{T, bool} $fn
     * @return T
     */
    public static function run(string $dir, string $name, \Closure $fn): mixed
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Lock directory {$dir} cannot be created.");
        }
        $path = $dir . '/' . $name . '.lock';
        $handle = null;
        for ($try = 0; $try < 10; $try++) {
            $handle = fopen($path, 'c');
            if ($handle === false || !flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Lock {$path} cannot be taken.");
            }
            clearstatcache(true, $path);
            $current = @stat($path);
            if ($current !== false && $current['ino'] === fstat($handle)['ino']) {
                break;
            }
            flock($handle, LOCK_UN);
            fclose($handle);
            $handle = null;
        }
        if ($handle === null) {
            throw new \RuntimeException("Lock {$path} cannot be taken.");
        }
        try {
            [$result, $gone] = $fn();
            if ($gone) {
                // Before the release: a waiter on this file then retries on a fresh one and finds the row gone.
                @unlink($path);
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
