<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * The cached pages of one release, one file each:
 * {root}/{releaseKey}/{id[0..1]}/{id}.page, where root is storage/cache/pages.
 *
 * A file is "PFPC1\n", the length of a JSON header line, "\n", the header (headers, createdAt,
 * expiresAt, stale, body length) and the body. A file that does not parse (truncated, foreign,
 * an older format) reads as no page. Writes go to a temporary file in the same folder, then
 * rename(), so a reader sees the old page or the new one, never half of one.
 *
 * Keys are per release (releaseKey()): a deploy starts with an empty cache, and a storage
 * folder shared between releases (BG Genius) never serves another release's pages. On its
 * first write, a store removes the folders of other releases that have not been written to
 * in 24 hours.
 */
final class PageStore
{
    public const string MAGIC = "PFPC1\n";

    public const int PRUNE_AFTER = 86400;

    private readonly string $dir;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private bool $written = false;

    /**
     * @param string $root storage/cache/pages (absolute)
     * @param (\Closure(): float)|null $clock microtime(true) when null
     */
    public function __construct(
        private readonly string $root,
        private readonly string $releaseKey,
        ?\Closure $clock = null,
    ) {
        $this->dir = rtrim($root, '/') . '/' . $releaseKey;
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * The release key of the application at $basePath: a hash of its real path and the mtimes
     * of the boot manifest (written by `preflow optimize` on deploy) and of Composer's
     * installed.json. A new release folder, a new manifest or a composer install is a new key.
     */
    public static function releaseKey(string $basePath): string
    {
        $base = rtrim($basePath, '/');
        $real = realpath($base) ?: $base;
        $manifest = \Preflow\Core\Boot\ManifestFile::path($real);
        $installed = $real . '/vendor/composer/installed.json';
        clearstatcache(true, $manifest);
        clearstatcache(true, $installed);
        $parts = [$real, (string) (@filemtime($manifest) ?: '-'), (string) (@filemtime($installed) ?: '-')];

        return substr(hash('sha256', implode("\n", $parts)), 0, 16);
    }

    public function releaseDir(): string
    {
        return $this->dir;
    }

    public function get(PageKey $key): ?StoredPage
    {
        return $this->read($this->path($key->id()));
    }

    public function put(PageKey $key, StoredPage $page): void
    {
        $this->write($key->id(), $page);
    }

    public function forget(string $id): void
    {
        $file = $this->path($id);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** Marks a page stale (served while a fresh one is built). No-op when there is no such page. */
    public function markStale(string $id): void
    {
        $page = $this->read($this->path($id));
        if ($page !== null && !$page->stale) {
            $this->write($id, $page->withStale());
        }
    }

    /** Removes every page of this release; returns how many there were. */
    public function clear(): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }
        $count = 0;
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $shard) {
            foreach (glob($shard . '/*', GLOB_NOSORT) ?: [] as $file) {
                if (str_ends_with($file, '.page') && @unlink($file)) {
                    $count++;
                } elseif (str_ends_with($file, '.tmp')) {
                    @unlink($file);
                }
            }
            @rmdir($shard);
        }

        return $count;
    }

    private function path(string $id): string
    {
        if (!preg_match('/^[0-9a-f]{40}$/', $id)) {
            throw new \InvalidArgumentException("Not a page id: '{$id}'.");
        }

        return $this->dir . '/' . substr($id, 0, 2) . '/' . $id . '.page';
    }

    private function read(string $file): ?StoredPage
    {
        $data = @file_get_contents($file);
        if ($data === false || !str_starts_with($data, self::MAGIC)) {
            return null;
        }
        $offset = strlen(self::MAGIC);
        $eol = strpos($data, "\n", $offset);
        if ($eol === false || $eol - $offset > 10) {
            return null;
        }
        $length = substr($data, $offset, $eol - $offset);
        if (!ctype_digit($length)) {
            return null;
        }
        $meta = json_decode(substr($data, $eol + 1, (int) $length), true);
        if (!is_array($meta) || !is_array($meta['h'] ?? null) || !is_int($meta['b'] ?? null)
            || !(is_float($meta['c'] ?? null) || is_int($meta['c'] ?? null))) {
            return null;
        }
        $body = substr($data, $eol + 1 + (int) $length);
        if (strlen($body) !== $meta['b']) {
            return null; // truncated or padded
        }
        $headers = [];
        foreach ($meta['h'] as $name => $values) {
            if (!is_string($name) || !is_array($values)) {
                return null;
            }
            $headers[$name] = array_values(array_map(strval(...), $values));
        }
        $expires = $meta['e'] ?? null;

        return new StoredPage(
            $body,
            $headers,
            (float) $meta['c'],
            is_int($expires) || is_float($expires) ? (float) $expires : null,
            ($meta['s'] ?? false) === true,
        );
    }

    private function write(string $id, StoredPage $page): void
    {
        $file = $this->path($id);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create {$dir}.");
        }
        if (!$this->written) {
            $this->written = true;
            @touch($this->dir);
            $this->pruneOtherReleases();
        }
        $meta = json_encode([
            'h' => $page->headers,
            'c' => $page->createdAt,
            'e' => $page->expiresAt,
            's' => $page->stale,
            'b' => strlen($page->body),
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $data = self::MAGIC . strlen($meta) . "\n" . $meta . $page->body;
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $data) !== strlen($data)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not write {$tmp}.");
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not move the page to {$file}.");
        }
    }

    /**
     * Removes the folders of other releases not written to in PRUNE_AFTER seconds: the newest
     * mtime of the folder and its index files counts.
     */
    private function pruneOtherReleases(): void
    {
        $cutoff = ($this->clock)() - self::PRUNE_AFTER;
        foreach (glob(rtrim($this->root, '/') . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $dir) {
            if (basename($dir) === $this->releaseKey || is_link($dir)) {
                continue;
            }
            $newest = 0;
            foreach ([$dir, $dir . '/' . TagIndex::FILE, $dir . '/' . TagIndex::FILE . '-wal'] as $path) {
                clearstatcache(true, $path);
                $newest = max($newest, (int) @filemtime($path));
            }
            if ($newest < $cutoff) {
                self::removeTree($dir);
            }
        }
    }

    /** Removes a folder and what is in it, never following symlinks. */
    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
