<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

use Preflow\Core\Cache\CacheCleared;
use Preflow\Core\Cache\CacheSource;
use Preflow\Core\Cache\CacheStats;

/**
 * The page cache in the CacheRegistry (key "pages"): `preflow cache:clear pages` and Folio's
 * Cache screen. It covers storage/cache/pages, every release: entries are the cached pages,
 * bytes everything in the folder (the indexes too).
 *
 * clear() empties the current release (its pages, and its index's rows, so an open connection
 * of a running request stays valid), records an EVERYTHING invalidation so no render in flight
 * stores an old page after it, and removes the folders of other releases. A clear is not a
 * refresh: the refresh queue, pending invalidations and the deploy warm-up go too (pages are
 * built on their next visit; `preflow pages:refresh --all` rebuilds them instead).
 */
final class PageCacheSource implements CacheSource
{
    /**
     * @param string $root storage/cache/pages (absolute)
     * @param \Closure(): string $releaseKey the current release key (PageStore::releaseKey()), asked only when needed
     */
    public function __construct(
        private readonly string $root,
        private readonly \Closure $releaseKey,
        private readonly ?bool $sqlite = null,
        private readonly ?\Closure $clock = null,
    ) {
        if ($root === '' || $root[0] !== '/' || rtrim($root, '/') === '') {
            throw new \InvalidArgumentException("The page cache folder must be an absolute path, got '{$root}'.");
        }
    }

    public function key(): string
    {
        return 'pages';
    }

    public function label(): string
    {
        return 'Page cache';
    }

    public function stats(): CacheStats
    {
        $entries = 0;
        $bytes = 0;
        foreach ($this->files($this->root) as $file) {
            $bytes += (int) $file->getSize();
            if (str_ends_with($file->getFilename(), '.page')) {
                $entries++;
            }
        }

        return new CacheStats($entries, $bytes);
    }

    public function clear(): CacheCleared
    {
        $root = rtrim($this->root, '/');
        if (!is_dir($root)) {
            return new CacheCleared(0, 0);
        }
        $current = ($this->releaseKey)();
        $entries = 0;
        $bytes = 0;
        foreach (glob($root . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $dir) {
            if (is_link($dir)) {
                continue;
            }
            if (basename($dir) !== $current) {
                foreach ($this->files($dir) as $file) {
                    $bytes += (int) $file->getSize();
                    $entries += str_ends_with($file->getFilename(), '.page') ? 1 : 0;
                }
                PageStore::removeTree($dir);
                continue;
            }
            foreach ($this->files($dir) as $file) {
                if (str_ends_with($file->getFilename(), '.page')) {
                    $bytes += (int) $file->getSize();
                }
            }
            $entries += (new PageStore($root, $current))->clear();
            $this->emptyIndex($dir);
            $cleared = true;
        }
        if (!isset($cleared)) {
            $this->emptyIndex($root . '/' . $current);
        }

        return new CacheCleared($entries, $bytes);
    }

    /**
     * Empties the current release's index rows (an open connection of a running request stays
     * valid) and records an EVERYTHING invalidation: a render that started before the clear
     * must not store its page after it.
     */
    private function emptyIndex(string $dir): void
    {
        $index = TagIndex::in($dir, $this->clock, $this->sqlite);
        $index->clear();
        $index->recordInvalidation([TagIndex::EVERYTHING], ($this->clock ?? static fn (): float => microtime(true))());
        $pdo = $index->pdo();
        if ($pdo !== null) {
            (new RefreshQueue($pdo, $this->clock))->clear();
        }
        $index->close();
        // Everything is gone: nothing older than the clear is left to invalidate or warm.
        (new PendingInvalidations($dir))->clear();
        @unlink($dir . '/' . PageStore::WARM_FROM);
    }

    /** @return \Generator<\SplFileInfo> the files under $dir, symlinks and dotfiles left out */
    private function files(string $dir): \Generator
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile() || $file->getFilename()[0] === '.') {
                continue;
            }
            yield $file;
        }
    }
}
