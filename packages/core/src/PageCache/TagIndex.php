<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * Which cached pages depend on which tags, in SQLite (WAL) next to the release's pages:
 * {root}/{releaseKey}/index.sqlite.
 *
 *   pages(id, url, path, fragment, has_query, created_at, parts)
 *                                                          one row per cached page; path is the
 *                                                          URL without its query, parts what
 *                                                          else its key holds (PageKey::parts(),
 *                                                          JSON), so a rebuild asks for that key
 *   tags(tag, page_id, freshness, timed_seconds)           what a page read while it rendered
 *   invalidations(tag, at)                                 recent changes, kept for an hour
 *
 * invalidations let a render that started before a change refuse to store what it built
 * (invalidatedSince()). The tag EVERYTHING ('*') stands for the whole site: an invalidation of
 * it counts for every tag (cache:clear records one). Other tables may live in the same file (the
 * refresh queue): pdo() hands out the connection, and they create theirs with CREATE TABLE IF
 * NOT EXISTS. The schema of this class's own tables is versioned (PRAGMA user_version); another
 * version is dropped and created anew, since an index is only a cache.
 *
 * Growth is bounded (PageCacheConfig max_pages, max_query_variants): record() evicts the oldest
 * pages beyond either limit, removes their rows and hands their ids to onEvict, which forgets
 * their files (forStore() wires that up).
 *
 * An index created anew, or set aside because it was corrupt (logged once), first empties the
 * release's page files: pages it has no tags for could never be refreshed.
 *
 * Without pdo_sqlite, or when the file cannot be opened, the index is unavailable and never
 * throws: available() is false, record() and forget() do nothing, pagesFor() is empty and
 * invalidatedSince() is true (nothing may be stored). The page cache treats that as disabled.
 */
final class TagIndex
{
    public const string FILE = 'index.sqlite';

    public const int INVALIDATIONS_KEPT = 3600;

    /** The tag of the whole site. */
    public const string EVERYTHING = '*';

    public const int SCHEMA = 3;

    /** Bound parameters per IN (...) list. */
    private const int CHUNK = 500;

    /** @var array<string, true> files whose failure was logged in this process */
    private static array $logged = [];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private readonly bool $sqlite;

    private ?\PDO $pdo = null;

    private bool $failed = false;

    /**
     * @param string $file the SQLite file (absolute)
     * @param (\Closure(): float)|null $clock microtime(true) when null
     * @param bool|null $sqlite whether pdo_sqlite can be used; null asks the extension list (a test seam)
     * @param int $maxPages pages kept at most, 0 = no limit
     * @param int $maxQueryVariants pages with a query kept per path at most, 0 = no limit
     * @param (\Closure(string): void)|null $onEvict called with each evicted page id, before the commit
     */
    public function __construct(
        private readonly string $file,
        ?\Closure $clock = null,
        ?bool $sqlite = null,
        private readonly int $maxPages = 0,
        private readonly int $maxQueryVariants = 0,
        private readonly ?\Closure $onEvict = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sqlite = $sqlite ?? (extension_loaded('pdo_sqlite') && in_array('sqlite', \PDO::getAvailableDrivers(), true));
    }

    /** The index of the release folder $releaseDir (PageStore::releaseDir()), without limits. */
    public static function in(string $releaseDir, ?\Closure $clock = null, ?bool $sqlite = null): self
    {
        return new self(rtrim($releaseDir, '/') . '/' . self::FILE, $clock, $sqlite);
    }

    /**
     * The index of $store's release, with $config's limits; evicted pages are forgotten in $store.
     * The way the page cache opens its index: the store is activated first (rollback check).
     */
    public static function forStore(PageStore $store, PageCacheConfig $config, ?\Closure $clock = null, ?bool $sqlite = null): self
    {
        $store->activate(); // a rollback empties the index before anything is recorded in it

        return new self(
            rtrim($store->releaseDir(), '/') . '/' . self::FILE,
            $clock,
            $sqlite,
            $config->maxPages,
            $config->maxQueryVariants,
            static function (string $id) use ($store): void {
                $store->forget($id);
            },
        );
    }

    /** Whether the index can be used; opens it (creating the file and tables) on first call. */
    public function available(): bool
    {
        return $this->pdo() !== null;
    }

    /**
     * The open connection, or null when the index is unavailable. For tables that share this
     * file (the refresh queue).
     */
    public function pdo(): ?\PDO
    {
        if ($this->pdo !== null || $this->failed) {
            return $this->pdo;
        }
        if (!$this->sqlite) {
            $this->failed = true;

            return null;
        }
        try {
            $this->pdo = $this->open();
        } catch (\PDOException $e) {
            if (!self::corrupt($e) || !is_file($this->file)) {
                $this->fail($e);

                return null;
            }
            $aside = $this->file . '.corrupt-' . date('Ymd-His');
            @rename($this->file, $aside);
            @unlink($this->file . '-wal');
            @unlink($this->file . '-shm');
            $this->log("Page cache index {$this->file} was corrupt ({$e->getMessage()}), set aside as {$aside}.");
            try {
                $this->pdo = $this->open();
            } catch (\Throwable $again) {
                $this->fail($again);
            }
        } catch (\Throwable $e) {
            $this->fail($e);
        }

        return $this->pdo;
    }

    private function open(): \PDO
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create {$dir}.");
        }
        $new = !is_file($this->file);
        $pdo = new \PDO('sqlite:' . $this->file, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() !== self::SCHEMA) {
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                // Checked again under the write lock: another process may have just done it.
                if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() !== self::SCHEMA) {
                    $pdo->exec('DROP TABLE IF EXISTS tags');
                    $pdo->exec('DROP TABLE IF EXISTS pages');
                    $pdo->exec('DROP TABLE IF EXISTS invalidations');
                    $pdo->exec('CREATE TABLE pages (
                        id TEXT PRIMARY KEY, url TEXT NOT NULL, path TEXT NOT NULL, fragment INTEGER NOT NULL,
                        has_query INTEGER NOT NULL, created_at REAL NOT NULL, parts TEXT NOT NULL DEFAULT \'\')');
                    $pdo->exec('CREATE INDEX pages_created ON pages (created_at)');
                    $pdo->exec('CREATE INDEX pages_path ON pages (path, has_query, created_at)');
                    $pdo->exec('CREATE TABLE tags (
                        tag TEXT NOT NULL, page_id TEXT NOT NULL, freshness TEXT NOT NULL, timed_seconds INTEGER,
                        PRIMARY KEY (tag, page_id))');
                    $pdo->exec('CREATE INDEX tags_page ON tags (page_id)');
                    $pdo->exec('CREATE TABLE invalidations (tag TEXT NOT NULL, at REAL NOT NULL)');
                    $pdo->exec('CREATE INDEX invalidations_tag_at ON invalidations (tag, at)');
                    $pdo->exec('PRAGMA user_version = ' . self::SCHEMA);
                    $new = true;
                }
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
        }
        if ($new) {
            // Pages from before this index have no tags here: they could never be refreshed.
            (new PageStore(dirname($dir), basename($dir)))->clear();
        }

        return $pdo;
    }

    private static function corrupt(\PDOException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'not a database') || str_contains($message, 'malformed')
            || str_contains($message, 'corrupt');
    }

    private function fail(\Throwable $e): void
    {
        $this->failed = true;
        $this->pdo = null;
        $this->log("Page cache index {$this->file} is unavailable, the page cache is off: {$e->getMessage()}");
    }

    private function log(string $message): void
    {
        if (!isset(self::$logged[$this->file])) {
            self::$logged[$this->file] = true;
            error_log($message);
        }
    }

    /**
     * Records what a page depends on, replacing what was recorded for it before, then evicts the
     * oldest pages beyond max_query_variants (pages with a query on this page's path) and
     * max_pages. Returns the evicted ids (onEvict was called with each).
     *
     * @param array<string, array{0: Freshness, 1?: int|null}|Freshness> $tags tag => [Freshness, ?seconds]
     *        (the seconds of a Timed dependency)
     * @param array<string, mixed> $parts PageKey::parts(): what a rebuild needs to ask for the same key
     * @return list<string>
     */
    public function record(string $pageId, string $url, bool $fragment, bool $hasQuery, array $tags, array $parts = []): array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        $path = strstr($url, '?', true);
        $path = $path === false ? $url : $path;
        $evicted = [];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT OR REPLACE INTO pages (id, url, path, fragment, has_query, created_at, parts) VALUES (?, ?, ?, ?, ?, CAST(? AS REAL), ?)')
                ->execute([$pageId, $url, $path, (int) $fragment, (int) $hasQuery, self::real(($this->clock)()), self::encodeParts($parts)]);
            $pdo->prepare('DELETE FROM tags WHERE page_id = ?')->execute([$pageId]);
            $insert = $pdo->prepare('INSERT OR REPLACE INTO tags (tag, page_id, freshness, timed_seconds) VALUES (?, ?, ?, ?)');
            foreach ($tags as $tag => $spec) {
                [$freshness, $seconds] = $spec instanceof Freshness ? [$spec, null] : [$spec[0], $spec[1] ?? null];
                if (!$freshness instanceof Freshness) {
                    throw new \InvalidArgumentException("The freshness of tag '{$tag}' is not a Freshness.");
                }
                $insert->execute([(string) $tag, $pageId, $freshness->value, $freshness === Freshness::Timed ? $seconds : null]);
            }
            if ($hasQuery && $this->maxQueryVariants > 0) {
                $statement = $pdo->prepare('SELECT id FROM pages WHERE path = ? AND has_query = 1 AND id != ?
                    ORDER BY created_at DESC, id LIMIT -1 OFFSET ' . ($this->maxQueryVariants - 1));
                $statement->execute([$path, $pageId]);
                $evicted = $statement->fetchAll(\PDO::FETCH_COLUMN);
                $this->delete($pdo, $evicted);
            }
            if ($this->maxPages > 0) {
                $over = (int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn() - $this->maxPages;
                if ($over > 0) {
                    $statement = $pdo->prepare('SELECT id FROM pages WHERE id != ? ORDER BY created_at, id LIMIT ' . $over);
                    $statement->execute([$pageId]);
                    $oldest = $statement->fetchAll(\PDO::FETCH_COLUMN);
                    $this->delete($pdo, $oldest);
                    $evicted = [...$evicted, ...$oldest];
                }
            }
            // The files go before the commit: a crash in between leaves rows without a file (a
            // miss), never a file without rows (a page no change could refresh).
            $evicted = array_values(array_map(strval(...), $evicted));
            if ($this->onEvict !== null) {
                foreach ($evicted as $id) {
                    ($this->onEvict)($id);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $evicted;
    }

    /** @param list<string> $ids */
    private function delete(\PDO $pdo, array $ids): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $marks = self::marks($chunk);
            $pdo->prepare("DELETE FROM tags WHERE page_id IN ({$marks})")->execute($chunk);
            $pdo->prepare("DELETE FROM pages WHERE id IN ({$marks})")->execute($chunk);
        }
    }

    /** How many pages are recorded (0 when unavailable). */
    public function count(): int
    {
        $pdo = $this->pdo();

        return $pdo === null ? 0 : (int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn();
    }

    /**
     * The pages that depend on any of $tags, each with the strictest freshness among its
     * matching tags (Fresh > NextVisit > Timed).
     *
     * @param list<string> $tags
     * @return array<string, Freshness> page id => freshness
     */
    public function pagesFor(array $tags): array
    {
        $pdo = $this->pdo();
        $tags = array_values(array_unique(array_map(strval(...), $tags)));
        if ($pdo === null || $tags === []) {
            return [];
        }
        $pages = [];
        foreach (array_chunk($tags, self::CHUNK) as $chunk) {
            $statement = $pdo->prepare('SELECT page_id, freshness FROM tags WHERE tag IN (' . self::marks($chunk) . ')');
            $statement->execute($chunk);
            foreach ($statement->fetchAll(\PDO::FETCH_NUM) as [$pageId, $value]) {
                $freshness = Freshness::tryFrom((string) $value) ?? Freshness::Fresh;
                $pages[$pageId] = isset($pages[$pageId]) ? Freshness::strictest($pages[$pageId], $freshness) : $freshness;
            }
        }

        return $pages;
    }

    /**
     * The url and kind of a recorded page, null when it is not recorded.
     *
     * @return array{url: string, fragment: bool, hasQuery: bool, createdAt: float, parts: array<string, mixed>}|null
     */
    public function page(string $pageId): ?array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return null;
        }
        $statement = $pdo->prepare('SELECT url, fragment, has_query, created_at, parts FROM pages WHERE id = ?');
        $statement->execute([$pageId]);
        $row = $statement->fetch(\PDO::FETCH_NUM);

        return $row === false ? null : [
            'url' => (string) $row[0],
            'fragment' => (bool) $row[1],
            'hasQuery' => (bool) $row[2],
            'createdAt' => (float) $row[3],
            'parts' => self::decodeParts((string) $row[4]),
        ];
    }

    /**
     * The full pages without a query among $pageIds (all of them when null): the pages a refresh
     * rebuilds eagerly. Fragments and search or filter pages are only rebuilt on visit.
     *
     * @param list<string>|null $pageIds
     * @return array<string, array{url: string, parts: array<string, mixed>}> page id => url and key parts
     */
    public function fullPages(?array $pageIds = null): array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        $pages = [];
        $read = static function (\PDOStatement $statement) use (&$pages): void {
            foreach ($statement->fetchAll(\PDO::FETCH_NUM) as [$id, $url, $parts]) {
                $pages[(string) $id] = ['url' => (string) $url, 'parts' => self::decodeParts((string) $parts)];
            }
        };
        if ($pageIds === null) {
            $read($pdo->query('SELECT id, url, parts FROM pages WHERE fragment = 0 AND has_query = 0 ORDER BY created_at, id'));

            return $pages;
        }
        foreach (array_chunk(array_values(array_unique($pageIds)), self::CHUNK) as $chunk) {
            $statement = $pdo->prepare('SELECT id, url, parts FROM pages WHERE fragment = 0 AND has_query = 0 AND id IN (' . self::marks($chunk) . ')');
            $statement->execute($chunk);
            $read($statement);
        }

        return $pages;
    }

    /** The ids of the recorded pages (of every kind) at $url, the normalised URL a key gives. @return list<string> */
    public function idsAt(string $url): array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        $path = strstr($url, '?', true);
        // By path first: pages_path is indexed, url is not.
        $statement = $pdo->prepare('SELECT id FROM pages WHERE path = ? AND url = ?');
        $statement->execute([$path === false ? $url : $path, $url]);

        return array_map(strval(...), $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The tags these pages depend on.
     *
     * @param list<string> $pageIds
     * @return list<string>
     */
    public function tagsOf(array $pageIds): array
    {
        $pdo = $this->pdo();
        if ($pdo === null || $pageIds === []) {
            return [];
        }
        $tags = [];
        foreach (array_chunk(array_values(array_unique($pageIds)), self::CHUNK) as $chunk) {
            $statement = $pdo->prepare('SELECT DISTINCT tag FROM tags WHERE page_id IN (' . self::marks($chunk) . ')');
            $statement->execute($chunk);
            array_push($tags, ...array_map(strval(...), $statement->fetchAll(\PDO::FETCH_COLUMN)));
        }

        return array_values(array_unique($tags));
    }

    /** @param array<string, mixed> $parts */
    private static function encodeParts(array $parts): string
    {
        return $parts === [] ? '' : json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @return array<string, mixed> */
    public static function decodeParts(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $parts = json_decode($json, true);

        return is_array($parts) ? $parts : [];
    }

    /** Removes a page and its tags. */
    public function forget(string $pageId): void
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return;
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM tags WHERE page_id = ?')->execute([$pageId]);
            $pdo->prepare('DELETE FROM pages WHERE id = ?')->execute([$pageId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Removes these pages and their tags, in one transaction.
     *
     * @param list<string> $pageIds
     */
    public function forgetMany(array $pageIds): void
    {
        $pdo = $this->pdo();
        $pageIds = array_values(array_unique(array_map(strval(...), $pageIds)));
        if ($pdo === null || $pageIds === []) {
            return;
        }
        $pdo->beginTransaction();
        try {
            foreach (array_chunk($pageIds, self::CHUNK) as $chunk) {
                $pdo->prepare('DELETE FROM tags WHERE page_id IN (' . self::marks($chunk) . ')')->execute($chunk);
                $pdo->prepare('DELETE FROM pages WHERE id IN (' . self::marks($chunk) . ')')->execute($chunk);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Removes every page and its tags, keeping the invalidations (unlike clear()): a render
     * that started before still finds what changed. Returns how many pages there were.
     */
    public function forgetAll(): int
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return 0;
        }
        $pdo->beginTransaction();
        try {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn();
            $pdo->exec('DELETE FROM tags');
            $pdo->exec('DELETE FROM pages');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $count;
    }

    /**
     * Notes that $tags changed at $at (microtime), so a render that started earlier and read
     * one of them is not stored (invalidatedSince()). Entries older than INVALIDATIONS_KEPT
     * seconds are pruned.
     *
     * @param list<string> $tags
     */
    public function recordInvalidation(array $tags, float $at): void
    {
        $pdo = $this->pdo();
        $tags = array_values(array_unique(array_map(strval(...), $tags)));
        if ($pdo === null || $tags === []) {
            return;
        }
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO invalidations (tag, at) VALUES (?, CAST(? AS REAL))');
            foreach ($tags as $tag) {
                $insert->execute([$tag, self::real($at)]);
            }
            $pdo->prepare('DELETE FROM invalidations WHERE at < CAST(? AS REAL)')->execute([self::real(($this->clock)() - self::INVALIDATIONS_KEPT)]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Whether any of $tags, or EVERYTHING, changed after $since (microtime). True when the index
     * is unavailable, so nothing is stored that could be stale.
     *
     * @param list<string> $tags
     */
    public function invalidatedSince(array $tags, float $since): bool
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return true;
        }
        $tags = array_values(array_unique([self::EVERYTHING, ...array_map(strval(...), $tags)]));
        foreach (array_chunk($tags, self::CHUNK) as $chunk) {
            $statement = $pdo->prepare('SELECT 1 FROM invalidations WHERE at >= CAST(? AS REAL) AND tag IN (' . self::marks($chunk) . ') LIMIT 1');
            $statement->execute([self::real($since), ...$chunk]);
            if ($statement->fetchColumn() !== false) {
                return true;
            }
        }

        return false;
    }

    /** Empties the pages, tags and invalidations; returns how many pages were recorded. */
    public function clear(): int
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return 0;
        }
        $count = (int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn();
        $pdo->exec('DELETE FROM tags');
        $pdo->exec('DELETE FROM pages');
        $pdo->exec('DELETE FROM invalidations');

        return $count;
    }

    /** Closes the connection (tests, before removing the file). */
    public function close(): void
    {
        $this->pdo = null;
        $this->failed = false;
    }

    /** @param list<string> $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * $time as SQL text with microseconds: PDO binds a float through `precision` (14 digits),
     * which at today's timestamps keeps only 1/10 000 s, so an invalidation right after a render
     * started could compare as not after it.
     */
    private static function real(float $time): string
    {
        return sprintf('%.6F', $time);
    }
}
