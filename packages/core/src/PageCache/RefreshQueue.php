<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * The pages waiting to be rebuilt, in a table of the release's index.sqlite (TagIndex::pdo()):
 *
 *   refresh_queue(id, url, parts, verified, priority, queued_at, version, lease_until, attempts)
 *
 * - id: the page's key (PageKey::id()), or "url:" + sha1 of the URL for a URL the index doesn't
 *   know (a refresh asked for by hand). One row per id: a page queued again (a bulk import that
 *   touches it 300 times) stays one row, with the better of the two priorities.
 * - parts: the key parts (PageKey::parts(), JSON) the rebuild request carries, so it renders
 *   under the key visitors read.
 * - verified: the page was stored in this release (verify 'first' doesn't render it twice).
 * - priority: PRIORITY_RECORD (it showed a changed record or address) before PRIORITY_PAGE
 *   (a listing, a stale page) before PRIORITY_WARM (everything, a deploy's warm-up); then oldest first.
 *
 * Claim with lease: claim() takes the next row whose lease ran out and sets its lease, in one
 * write transaction (BEGIN IMMEDIATE), so two processes (the work after a response and the cron)
 * never take the same row; a process that dies leaves its row to be taken again once the lease
 * ends. version counts the times a row was queued: complete() removes it only when nobody
 * queued it again since the claim (that change came after the render started: the row stays,
 * its lease cleared, to be rebuilt once more). fail() clears the lease and counts the attempt;
 * after MAX_ATTEMPTS the row is dropped (its next visitor renders it).
 */
final class RefreshQueue
{
    public const string TABLE = 'refresh_queue';

    public const int PRIORITY_RECORD = 0;

    public const int PRIORITY_PAGE = 1;

    public const int PRIORITY_WARM = 2;

    public const int MAX_ATTEMPTS = 3;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private bool $created = false;

    /** @param (\Closure(): float)|null $clock microtime(true) when null */
    public function __construct(
        private readonly \PDO $pdo,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** The queue id of a URL the index doesn't know. */
    public static function urlId(string $url): string
    {
        return 'url:' . sha1($url);
    }

    private function table(): \PDO
    {
        if (!$this->created) {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                id TEXT PRIMARY KEY, url TEXT NOT NULL, parts TEXT NOT NULL, verified INTEGER NOT NULL,
                priority INTEGER NOT NULL, queued_at REAL NOT NULL, version INTEGER NOT NULL DEFAULT 1,
                lease_until REAL NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS ' . self::TABLE . '_next ON ' . self::TABLE . ' (priority, queued_at)');
            $this->created = true;
        }

        return $this->pdo;
    }

    /**
     * Queues pages, in one transaction. Returns how many were given.
     *
     * @param array<string, array{url: string, parts?: array<string, mixed>, verified?: bool, priority?: int}> $pages id => page
     */
    public function push(array $pages): int
    {
        if ($pages === []) {
            return 0;
        }
        $pdo = $this->table();
        $now = sprintf('%.6F', ($this->clock)());
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('INSERT INTO ' . self::TABLE . ' (id, url, parts, verified, priority, queued_at)
                VALUES (?, ?, ?, ?, ?, CAST(? AS REAL))
                ON CONFLICT(id) DO UPDATE SET url = excluded.url, parts = excluded.parts,
                    verified = MAX(verified, excluded.verified), priority = MIN(priority, excluded.priority),
                    version = version + 1, attempts = 0');
            foreach ($pages as $id => $page) {
                $parts = $page['parts'] ?? [];
                $statement->execute([
                    (string) $id,
                    $page['url'],
                    $parts === [] ? '' : json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    (int) ($page['verified'] ?? false),
                    $page['priority'] ?? self::PRIORITY_PAGE,
                    $now,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return count($pages);
    }

    /**
     * Takes the next page to rebuild for $lease seconds, null when none is free.
     *
     * @return array{id: string, url: string, parts: array<string, mixed>, verified: bool, priority: int, version: int}|null
     */
    public function claim(float $lease): ?array
    {
        $pdo = $this->table();
        $now = ($this->clock)();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $statement = $pdo->prepare('SELECT id, url, parts, verified, priority, version FROM ' . self::TABLE . '
                WHERE lease_until <= CAST(? AS REAL) ORDER BY priority, queued_at, id LIMIT 1');
            $statement->execute([sprintf('%.6F', $now)]);
            $row = $statement->fetch(\PDO::FETCH_NUM);
            if ($row !== false) {
                $pdo->prepare('UPDATE ' . self::TABLE . ' SET lease_until = CAST(? AS REAL) WHERE id = ?')
                    ->execute([sprintf('%.6F', $now + $lease), $row[0]]);
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        if ($row === false) {
            return null;
        }

        return [
            'id' => (string) $row[0],
            'url' => (string) $row[1],
            'parts' => TagIndex::decodeParts((string) $row[2]),
            'verified' => (bool) $row[3],
            'priority' => (int) $row[4],
            'version' => (int) $row[5],
        ];
    }

    /**
     * The claimed page is done: removed, unless it was queued again since the claim (then its
     * lease is cleared, so it is rebuilt once more).
     *
     * @param array{id: string, version: int} $claimed
     */
    public function complete(array $claimed): void
    {
        $pdo = $this->table();
        $statement = $pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ? AND version = ?');
        $statement->execute([$claimed['id'], $claimed['version']]);
        if ($statement->rowCount() === 0) {
            $pdo->prepare('UPDATE ' . self::TABLE . ' SET lease_until = 0 WHERE id = ?')->execute([$claimed['id']]);
        }
    }

    /**
     * The rebuild of the claimed page failed: free again, or dropped after MAX_ATTEMPTS.
     *
     * @param array{id: string} $claimed
     */
    public function fail(array $claimed): void
    {
        $pdo = $this->table();
        $pdo->prepare('UPDATE ' . self::TABLE . ' SET lease_until = 0, attempts = attempts + 1 WHERE id = ?')->execute([$claimed['id']]);
        $pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ? AND attempts >= ?')->execute([$claimed['id'], self::MAX_ATTEMPTS]);
    }

    /** How many pages are queued (claimed ones too). */
    public function count(): int
    {
        return (int) $this->table()->query('SELECT COUNT(*) FROM ' . self::TABLE)->fetchColumn();
    }

    /**
     * What is queued, in the order it is taken (tests, the Cache screen).
     *
     * @return list<array{id: string, url: string, priority: int, verified: bool}>
     */
    public function all(): array
    {
        $rows = $this->table()->query('SELECT id, url, priority, verified FROM ' . self::TABLE . ' ORDER BY priority, queued_at, id')->fetchAll(\PDO::FETCH_NUM);

        return array_map(static fn (array $row): array => [
            'id' => (string) $row[0],
            'url' => (string) $row[1],
            'priority' => (int) $row[2],
            'verified' => (bool) $row[3],
        ], $rows);
    }

    /** Empties the queue; returns how many pages were queued. */
    public function clear(): int
    {
        $pdo = $this->table();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . self::TABLE)->fetchColumn();
        $pdo->exec('DELETE FROM ' . self::TABLE);

        return $count;
    }
}
