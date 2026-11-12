<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * The cached pages of one release, one file each:
 * {root}/{releaseKey}/{id[0..1]}/{id}.page, where root is storage/cache/pages.
 *
 * A file is "PFPC1\n", the length of a JSON header line, "\n", the header (headers, createdAt,
 * expiresAt, stale, body length, the carried render state when there is some) and the body. A file that does not parse (truncated, foreign,
 * an older format) reads as no page. Writes go to a temporary file in the same folder, then
 * rename(), so a reader sees the old page or the new one, never half of one.
 *
 * A stale mark is a separate, empty marker file next to the page, {id}.stale: markStale() never
 * rewrites a page, so it can't put back an old body over one stored or forgotten meanwhile.
 * put() removes the marker after its rename, forget() removes both.
 *
 * Keys are per release (releaseKey()): a deploy starts with an empty cache, and a storage
 * folder shared between releases (a deployment with a shared storage path) never serves another release's pages. The file
 * {root}/active names the release that last used the cache. A store's first use in a process
 * compares it with its own key: when another release was active since (a rollback to an older
 * release folder), its own pages are older than the content, so it empties its folder and index
 * first, then names itself. On its first write, a store removes the folders of other releases
 * not written to in 24 hours, never the active one.
 *
 * A release that takes over from another one (a deploy, a rollback) notes which it was in
 * {releaseDir}/warm-from: the refresher queues the full pages of that release's index for
 * rebuilding (Refresher::work(), `preflow pages:work`), so the new release starts empty and is
 * warmed from the URLs visitors used before.
 *
 * An in-place deploy (git pull in the same folder, no `optimize`, no composer install) keeps the
 * release key: it needs `preflow cache:clear pages` or `preflow optimize`.
 */
final class PageStore
{
    public const string MAGIC = "PFPC1\n";

    public const int PRUNE_AFTER = 86400;

    public const string ACTIVE = 'active';

    /** In a release folder: the release that was active before this one claimed it. */
    public const string WARM_FROM = 'warm-from';

    private readonly string $dir;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private bool $written = false;

    private bool $activated = false;

    private ?\Closure $beforeLock = null;

    private ?\Closure $beforeFiles = null;

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

    /** The page, with stale set when its marker exists. */
    public function get(PageKey $key): ?StoredPage
    {
        return $this->page($key->id());
    }

    /** The page with id $id (PageKey::id()), with stale set when its marker exists; null when none. */
    public function page(string $id): ?StoredPage
    {
        $this->activate();
        $file = $this->path($id);
        $page = $this->read($file);
        if ($page !== null && !$page->stale && is_file(self::marker($file))) {
            return $page->withStale();
        }

        return $page;
    }

    /**
     * Stores a page (its stale flag is ignored; markStale() marks). A HEAD key is refused: its
     * response has no body, and GET shares the key. Returns whether the page was stored.
     *
     * @throws \RuntimeException when the file can't be written (the caller logs it; a store must
     *         never fail a request)
     */
    public function put(PageKey $key, StoredPage $page): bool
    {
        if ($key->isHead()) {
            return false;
        }
        $this->activate();
        $this->write($key->id(), $page->stale ? $page->withStale(false) : $page);

        return true;
    }

    public function forget(string $id): void
    {
        $this->activate();
        $file = $this->path($id);
        @unlink($file);
        @unlink(self::marker($file));
    }

    /**
     * Marks a page stale (served while a fresh one is built) with its marker file. No-op when
     * there is no such page.
     */
    public function markStale(string $id): void
    {
        $this->activate();
        $file = $this->path($id);
        if (is_file($file)) {
            @touch(self::marker($file));
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
                } elseif (str_ends_with($file, '.tmp') || str_ends_with($file, '.stale')) {
                    @unlink($file);
                }
            }
            @rmdir($shard);
        }

        return $count;
    }

    /**
     * First use in this process (every public method but clear() calls it; TagIndex::forStore()
     * too, so a rollback is handled before the index is used): when {root}/active names another
     * release, this release's pages predate what that release did (a rollback), so they go, with
     * the index rows. Without an active file (the first use ever) the release just claims it.
     *
     * The check, clear and claim run under an exclusive flock on {root}/active.lock, and `active`
     * is read again once the lock is held: of the requests that start together after a rollback,
     * one clears and claims, the others then find their own key and return, so none of them clears
     * rows another has recorded since. The rows go before the files: a page stored in between
     * loses its file (a miss), never its rows. The usual case, `active` naming this release, takes
     * no lock.
     */
    public function activate(): void
    {
        if ($this->activated) {
            return;
        }
        $this->activated = true;
        if ($this->activeKey() === $this->releaseKey) {
            return;
        }
        if ($this->beforeLock !== null) {
            ($this->beforeLock)();
        }
        $root = rtrim($this->root, '/');
        if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
            return; // nothing can be stored either
        }
        $lock = @fopen($root . '/' . self::ACTIVE . '.lock', 'c');
        if ($lock !== false) {
            @flock($lock, LOCK_EX);
        }
        try {
            $active = $this->activeKey(); // again, under the lock
            if ($active === $this->releaseKey) {
                return;
            }
            if ($active !== null && preg_match('/^[0-9a-f]{16}$/', $active) === 1
                && (is_dir($this->dir) || @mkdir($this->dir, 0775, true) || is_dir($this->dir))) {
                // Its URLs warm this release (Refresher): written before the claim below.
                @file_put_contents($this->dir . '/' . self::WARM_FROM, $active);
            }
            if ($active !== null && is_dir($this->dir)) {
                if (is_file($this->dir . '/' . TagIndex::FILE)) {
                    try {
                        $index = TagIndex::in($this->dir);
                        $index->clear();
                        // Its old queue too: those pages are gone (the warm-up queues what is wanted).
                        $pdo = $index->pdo();
                        if ($pdo !== null) {
                            (new RefreshQueue($pdo))->clear();
                        }
                        $index->close();
                    } catch (\Throwable) {
                        // An index that can't be emptied: unavailable, so the cache is off anyway.
                    }
                }
                // Pending invalidations concerned the pages just removed.
                (new PendingInvalidations($this->dir))->clear();
                if ($this->beforeFiles !== null) {
                    ($this->beforeFiles)();
                }
                $this->clear(); // rows first, then files: never a file the index doesn't know
            }
            $tmp = $root . '/' . self::ACTIVE . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $this->releaseKey) === strlen($this->releaseKey)) {
                @rename($tmp, $root . '/' . self::ACTIVE) || @unlink($tmp);
            } else {
                @unlink($tmp);
            }
        } finally {
            if ($lock !== false) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    /**
     * Runs $hook in activate() after the unlocked read of `active` and before the lock: tests
     * interleave a second activation there.
     *
     * @internal
     */
    public function beforeActivationLock(\Closure $hook): void
    {
        $this->beforeLock = $hook;
    }

    /**
     * Runs $hook in a rollback's activate() after the index rows are emptied and before the
     * page files go: tests check the order there.
     *
     * @internal
     */
    public function beforeActivationFiles(\Closure $hook): void
    {
        $this->beforeFiles = $hook;
    }

    /** The release key {root}/active names, null when there is none. */
    /** The release key this store holds pages of. */
    public function release(): string
    {
        return $this->releaseKey;
    }

    /** The release named in {root}/active (the one that last used the cache), null when none or not a key. */
    public function activeRelease(): ?string
    {
        $key = $this->activeKey();

        return $key !== null && preg_match('/^[0-9a-f]{16}$/', $key) === 1 ? $key : null;
    }

    /** A store of another release under the same root (same clock). */
    public function forRelease(string $releaseKey): self
    {
        return new self($this->root, $releaseKey, $this->clock);
    }

    private function activeKey(): ?string
    {
        $value = @file_get_contents(rtrim($this->root, '/') . '/' . self::ACTIVE);

        return is_string($value) && $value !== '' ? trim($value) : null;
    }

    private static function marker(string $file): string
    {
        return substr($file, 0, -strlen('.page')) . '.stale';
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
        $state = $meta['r'] ?? [];
        if (!is_array($state)) {
            return null;
        }

        return new StoredPage(
            $body,
            $headers,
            (float) $meta['c'],
            is_int($expires) || is_float($expires) ? (float) $expires : null,
            ($meta['s'] ?? false) === true,
            $state,
        );
    }

    private function write(string $id, StoredPage $page): void
    {
        $file = $this->path($id);
        $dir = dirname($file);
        self::ensureDir($dir);
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
        ] + ($page->state === [] ? [] : ['r' => $page->state]), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $data = self::MAGIC . strlen($meta) . "\n" . $meta . $page->body;
        // A cache:clear can remove the shard folder between mkdir and the write: once more.
        for ($attempt = 1; ; $attempt++) {
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $data) === strlen($data) && @rename($tmp, $file)) {
                break;
            }
            @unlink($tmp);
            if ($attempt === 2) {
                throw new \RuntimeException("Could not write the page {$file}.");
            }
            self::ensureDir($dir);
        }
        @unlink(self::marker($file));
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create {$dir}.");
        }
    }

    /**
     * Removes the folders of other releases not written to in PRUNE_AFTER seconds: the newest
     * mtime of the folder and its index files counts. Never this release or the active one.
     */
    private function pruneOtherReleases(): void
    {
        $cutoff = ($this->clock)() - self::PRUNE_AFTER;
        $active = $this->activeKey();
        foreach (glob(rtrim($this->root, '/') . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $dir) {
            $name = basename($dir);
            if ($name === $this->releaseKey || $name === $active || is_link($dir)) {
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
