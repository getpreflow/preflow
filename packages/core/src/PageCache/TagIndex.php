<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * Which cached pages depend on which tags, in SQLite (WAL) next to the release's pages:
 * {root}/{releaseKey}/index.sqlite.
 *
 *   pages(id, url, fragment, has_query, created_at)       one row per cached page
 *   tags(tag, page_id, freshness, timed_seconds)           what a page read while it rendered
 *   invalidations(tag, at)                                 recent changes, kept for an hour
 *
 * invalidations let a render that started before a change refuse to store what it built
 * (invalidatedSince()). Other tables may live in the same file (the refresh queue): pdo()
 * hands out the connection, and they create theirs with CREATE TABLE IF NOT EXISTS.
 *
 * Without pdo_sqlite, or when the file cannot be opened, the index is unavailable and never
 * throws: available() is false, record() and forget() do nothing, pagesFor() is empty and
 * invalidatedSince() is true (nothing may be stored). The page cache treats that as disabled.
 */
final class TagIndex
{
    public const string FILE = 'index.sqlite';

    public const int INVALIDATIONS_KEPT = 3600;

    /** Bound parameters per IN (...) list. */
    private const int CHUNK = 500;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private readonly bool $sqlite;

    private ?\PDO $pdo = null;

    private bool $failed = false;

    /**
     * @param string $file the SQLite file (absolute)
     * @param (\Closure(): float)|null $clock microtime(true) when null
     * @param bool|null $sqlite whether pdo_sqlite can be used; null asks the extension list (a test seam)
     */
    public function __construct(
        private readonly string $file,
        ?\Closure $clock = null,
        ?bool $sqlite = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sqlite = $sqlite ?? (extension_loaded('pdo_sqlite') && in_array('sqlite', \PDO::getAvailableDrivers(), true));
    }

    /** The index of the release folder $releaseDir (PageStore::releaseDir()). */
    public static function in(string $releaseDir, ?\Closure $clock = null, ?bool $sqlite = null): self
    {
        return new self(rtrim($releaseDir, '/') . '/' . self::FILE, $clock, $sqlite);
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
            $dir = dirname($this->file);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Could not create {$dir}.");
            }
            $pdo = new \PDO('sqlite:' . $this->file, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('CREATE TABLE IF NOT EXISTS pages (
                id TEXT PRIMARY KEY, url TEXT NOT NULL, fragment INTEGER NOT NULL,
                has_query INTEGER NOT NULL, created_at REAL NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS tags (
                tag TEXT NOT NULL, page_id TEXT NOT NULL, freshness TEXT NOT NULL, timed_seconds INTEGER,
                PRIMARY KEY (tag, page_id))');
            $pdo->exec('CREATE INDEX IF NOT EXISTS tags_page ON tags (page_id)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS invalidations (tag TEXT NOT NULL, at REAL NOT NULL)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS invalidations_tag_at ON invalidations (tag, at)');
            $this->pdo = $pdo;
        } catch (\Throwable) {
            $this->failed = true;
        }

        return $this->pdo;
    }

    /**
     * Records what a page depends on, replacing what was recorded for it before.
     *
     * @param array<string, array{0: Freshness, 1?: int|null}|Freshness> $tags tag => [Freshness, ?seconds]
     *        (the seconds of a Timed dependency)
     */
    public function record(string $pageId, string $url, bool $fragment, bool $hasQuery, array $tags): void
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return;
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT OR REPLACE INTO pages (id, url, fragment, has_query, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$pageId, $url, (int) $fragment, (int) $hasQuery, ($this->clock)()]);
            $pdo->prepare('DELETE FROM tags WHERE page_id = ?')->execute([$pageId]);
            $insert = $pdo->prepare('INSERT OR REPLACE INTO tags (tag, page_id, freshness, timed_seconds) VALUES (?, ?, ?, ?)');
            foreach ($tags as $tag => $spec) {
                [$freshness, $seconds] = $spec instanceof Freshness ? [$spec, null] : [$spec[0], $spec[1] ?? null];
                if (!$freshness instanceof Freshness) {
                    throw new \InvalidArgumentException("The freshness of tag '{$tag}' is not a Freshness.");
                }
                $insert->execute([(string) $tag, $pageId, $freshness->value, $freshness === Freshness::Timed ? $seconds : null]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
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
     * @return array{url: string, fragment: bool, hasQuery: bool, createdAt: float}|null
     */
    public function page(string $pageId): ?array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return null;
        }
        $statement = $pdo->prepare('SELECT url, fragment, has_query, created_at FROM pages WHERE id = ?');
        $statement->execute([$pageId]);
        $row = $statement->fetch(\PDO::FETCH_NUM);

        return $row === false ? null : [
            'url' => (string) $row[0],
            'fragment' => (bool) $row[1],
            'hasQuery' => (bool) $row[2],
            'createdAt' => (float) $row[3],
        ];
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
            $insert = $pdo->prepare('INSERT INTO invalidations (tag, at) VALUES (?, ?)');
            foreach ($tags as $tag) {
                $insert->execute([$tag, $at]);
            }
            $pdo->prepare('DELETE FROM invalidations WHERE at < ?')->execute([($this->clock)() - self::INVALIDATIONS_KEPT]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Whether any of $tags changed after $since (microtime). True when the index is unavailable,
     * so nothing is stored that could be stale.
     *
     * @param list<string> $tags
     */
    public function invalidatedSince(array $tags, float $since): bool
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return true;
        }
        $tags = array_values(array_unique(array_map(strval(...), $tags)));
        foreach (array_chunk($tags, self::CHUNK) as $chunk) {
            $statement = $pdo->prepare('SELECT 1 FROM invalidations WHERE at > ? AND tag IN (' . self::marks($chunk) . ') LIMIT 1');
            $statement->execute([$since, ...$chunk]);
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
}
