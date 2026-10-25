<?php

declare(strict_types=1);

namespace Preflow\Core\Cache;

/**
 * A folder of cache files. The folder itself stays, and so does every file whose
 * name starts with a dot (.gitkeep); dot-named subfolders are still walked.
 * Symlinks are never followed or counted, clear() only unlinks them.
 * $pattern is an fnmatch() pattern on file basenames, applied at every depth.
 */
final class DirectoryCache implements CacheSource
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $dir,
        private readonly ?string $pattern = null,
        private readonly ?string $note = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function stats(): CacheStats
    {
        $entries = 0;
        $bytes = 0;
        foreach ($this->files() as $file) {
            if ($file->isLink()) {
                continue;
            }
            $entries++;
            $bytes += (int) @filesize($file->getPathname());
        }

        return new CacheStats($entries, $bytes, $this->note);
    }

    public function clear(): CacheCleared
    {
        $entries = 0;
        $bytes = 0;
        foreach ($this->files() as $file) {
            $isLink = $file->isLink();
            $size = $isLink ? 0 : (int) @filesize($file->getPathname());
            if (@unlink($file->getPathname()) && !$isLink) {
                if (str_ends_with($file->getFilename(), '.php') && function_exists('opcache_invalidate')) {
                    @opcache_invalidate($file->getPathname(), true);
                }
                $entries++;
                $bytes += $size;
            }
        }

        // Emptied subfolders go too (children first); the cache folder stays.
        if (is_dir($this->dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                if (!$item->isLink() && $item->isDir()) {
                    @rmdir($item->getPathname());
                }
            }
        }

        return new CacheCleared($entries, $bytes);
    }

    /** @return \Generator<\SplFileInfo> files and symlinks that match, dotfiles excluded */
    private function files(): \Generator
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            $name = $file->getFilename();
            if ($name[0] === '.') {
                continue;
            }
            if ($this->pattern !== null && !fnmatch($this->pattern, $name)) {
                continue;
            }
            yield $file;
        }
    }
}
