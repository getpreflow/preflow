<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Acts on what changed, and rebuilds.
 *
 * invalidate() records the invalidation, so that a render which started before and read one of
 * the tags does not store its page (TagIndex::invalidatedSince()), and then follows each page's
 * freshness for the tags it carries (the strictest of them, TagIndex::pagesFor()):
 *
 * - Fresh: the page is forgotten (its file and its index rows). A full page without a query is
 *   queued for rebuilding (RefreshQueue), pages that showed a changed record or address first;
 *   fragments and search or filter pages are only forgotten, their next visit renders them;
 * - NextVisit: the page is marked stale (PageStore::markStale()): it is still served while a
 *   fresh one is built: the stale hit queues it (stale());
 * - Timed: nothing. The page carries an expiresAt of its render time plus the time (set when it
 *   was stored) and refreshes then.
 *
 * So a page that read "other jobs" timed and its own job fresh is still forgotten when its job
 * changes: only the changed tags count.
 *
 * The tag TagIndex::EVERYTHING ('*') stands for every page, whatever its freshness (a raw write
 * whose tables are unsure, refreshAll()): all are forgotten and every full page without a query
 * is queued, last in line.
 *
 * work() rebuilds what is queued, within a time budget: the after-response work of a request
 * that queued something (Application::afterResponse(), page_cache.rebuild_budget) and
 * `preflow pages:work` from the cron. A rebuild is an internal anonymous GET (Rebuild::request())
 * handed to $rebuild (the Application renders it through its whole pipeline); a page already
 * fresh again (a visitor rendered it meanwhile) is skipped.
 *
 * A failed invalidation (SQLite busy, the index unusable) is kept in PendingInvalidations and
 * applied by the next invalidate(), work() or applyPending() that reaches the index; while it is
 * pending, nothing of the release is served or stored. Without pdo_sqlite nothing is cached and
 * invalidate() does nothing.
 *
 * A deploy: the new release's folder notes the release before it (PageStore::WARM_FROM); the
 * first time this refresher opens the new release's queue, the full pages of that release's
 * index are queued (verified anew, since templates changed), so `pages:work` warms them.
 */
final class Refresher
{
    /** How long a claimed rebuild is left to its process before another may take it (seconds). */
    public const float LEASE = 300.0;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var \Closure(string): void */
    private readonly \Closure $log;

    /** @var array<string, TagIndex> by release */
    private array $indexes = [];

    /** @var array<string, RefreshQueue> by release */
    private array $queues = [];

    private bool $working = false;

    /**
     * @param (\Closure(): float)|null $clock microtime(true) when null
     * @param bool|null $sqlite a test seam, as TagIndex's
     * @param (\Closure(ServerRequestInterface): ResponseInterface)|null $rebuild renders a rebuild
     *        request through the app (null: nothing is rebuilt, work() does nothing)
     * @param (\Closure(): void)|null $onQueue called when pages were queued in this store's release
     *        (the Application schedules the after-response work)
     * @param (\Closure(string): void)|null $log error_log() when null
     * @param string|null $origin scheme and host a path given to refresh() is resolved against (app.url)
     */
    public function __construct(
        private readonly PageStore $store,
        private readonly PageCacheConfig $config,
        ?\Closure $clock = null,
        private readonly ?bool $sqlite = null,
        private readonly ?\Closure $rebuild = null,
        private readonly ?\Closure $onQueue = null,
        ?\Closure $log = null,
        private readonly ?string $origin = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->log = $log ?? static function (string $message): void {
            error_log($message);
        };
    }

    /**
     * Forgets the pages carrying any of $tags in this store's release, and in the release named
     * active when that is another one: a command still running from the release before a
     * deploy (a cron import) also reaches the pages the new release built meanwhile.
     *
     * @param list<string> $tags what changed (DataTags::changes() applied)
     */
    public function invalidate(array $tags): void
    {
        $tags = array_values(array_unique(array_map(strval(...), $tags)));
        if ($tags === [] || !$this->usable()) {
            return; // without pdo_sqlite nothing is cached: no store activation, no file touched
        }
        $at = ($this->clock)();
        // Read before this store's own first use, which may claim active for itself.
        $active = $this->store->activeRelease();
        $this->invalidateIn($this->store, $tags, $at);
        if ($active !== null && $active !== $this->store->release()) {
            $other = $this->store->forRelease($active);
            if (is_dir($other->releaseDir())) {
                $this->invalidateIn($other, $tags, $at);
            }
        }
    }

    /**
     * A stale page was served (marked, expired or older than max_age): its rebuild is queued.
     * When it can't be queued, the page is forgotten instead, so the next visitor renders it.
     */
    public function stale(PageKey $key): void
    {
        if (!$this->usable() || $key->isFragment() || $key->hasQuery()) {
            // Built on their next visit: served stale this once.
            $this->store->forget($key->id());

            return;
        }
        try {
            $index = $this->index($this->store);
            if (!$index->available()) {
                throw new \RuntimeException('the index is unavailable');
            }
            $this->push($this->store, $index, [$key->id() => [
                'url' => $key->url(),
                'parts' => $key->parts(),
                'verified' => true,
                'priority' => RefreshQueue::PRIORITY_PAGE,
            ]]);
        } catch (\Throwable $e) {
            ($this->log)('page cache: the rebuild of ' . $key->url() . ' could not be queued: ' . $e->getMessage());
            $this->store->forget($key->id());
        }
    }

    /**
     * Refreshes the page at $url (absolute, or a path resolved against the origin): every page
     * stored at that URL (each locale and fragment variant) is forgotten, and the full page is
     * queued first in line. A URL with a query is only forgotten. Returns how many pages were
     * forgotten.
     *
     * @throws \InvalidArgumentException a URL the page cache doesn't cache (another host, not http)
     * @throws \RuntimeException the index is unavailable
     */
    public function refresh(string $url): int
    {
        $key = $this->keyOf($url);
        if (!$this->usable()) {
            return 0;
        }
        $index = $this->index($this->store);
        if (!$index->available()) {
            throw new \RuntimeException('The page cache index is unavailable.');
        }
        $ids = $index->idsAt($key->url());
        $full = $index->fullPages($ids);
        $index->forgetMany($ids);
        foreach ($ids as $id) {
            $this->store->forget($id);
        }
        if (!$key->hasQuery()) {
            $pages = [];
            foreach ($full as $id => $page) {
                $pages[$id] = $page + ['verified' => true, 'priority' => RefreshQueue::PRIORITY_RECORD];
            }
            if ($pages === []) {
                $pages[RefreshQueue::urlId($key->url())] = ['url' => $key->url(), 'priority' => RefreshQueue::PRIORITY_RECORD];
            }
            $this->push($this->store, $index, $pages);
        }

        return count($ids);
    }

    /** Forgets every page and queues every full page without a query. Returns how many are queued. */
    public function refreshAll(): int
    {
        $this->invalidate([TagIndex::EVERYTHING]);

        return $this->queued();
    }

    /**
     * Rebuilds queued pages of this store's release, highest priority first, until $budgetSeconds
     * have passed (checked after each, so at least one is taken). Returns how many were rendered
     * (pages found fresh again are skipped and not counted). Does nothing with a budget of 0 or
     * less, without a renderer, when the index is unavailable, while invalidations stay pending,
     * or when called from inside a rebuild.
     */
    public function work(float $budgetSeconds): int
    {
        if ($budgetSeconds <= 0 || $this->rebuild === null || $this->working || !$this->usable()) {
            return 0;
        }
        $this->working = true;
        $started = ($this->clock)();
        $rendered = 0;
        try {
            $index = $this->index($this->store);
            if (!$index->available() || !$this->applyPendingIn($this->store, $index)) {
                return 0; // nothing may be stored while an invalidation is pending
            }
            $queue = $this->queue($this->store, $index);
            do {
                $page = $queue->claim(self::LEASE);
                if ($page === null) {
                    break;
                }
                $id = str_starts_with($page['id'], 'url:') ? null : $page['id'];
                try {
                    if ($id !== null) {
                        $stored = $this->store->page($id);
                        if ($stored !== null && !$stored->staleAt(($this->clock)(), $this->config->maxAge)) {
                            $queue->complete($page); // a visitor rendered it meanwhile

                            continue;
                        }
                    }
                    $response = ($this->rebuild)(Rebuild::request($page['url'], $page['parts'], $id, $page['verified']));
                    $rendered++;
                    if ($response->getHeaderLine(PageCacheMiddleware::SKIP_HEADER) === 'invalidated') {
                        // A write while it rendered: not stored, built once more (MAX_ATTEMPTS at most).
                        $queue->fail($page);

                        continue;
                    }
                    $queue->complete($page);
                } catch (\Throwable $e) {
                    ($this->log)('page cache: the rebuild of ' . $page['url'] . ' failed: ' . $e->getMessage());
                    $queue->fail($page);
                }
            } while (($this->clock)() - $started < $budgetSeconds);
        } catch (\Throwable $e) {
            ($this->log)('page cache: rebuilding stopped: ' . $e->getMessage());
        } finally {
            $this->working = false;
        }

        return $rendered;
    }

    /** How many pages of this store's release are queued (0 when the index is unavailable). */
    public function queued(): int
    {
        if (!$this->usable()) {
            return 0;
        }
        $index = $this->index($this->store);

        return $index->available() ? $this->queue($this->store, $index)->count() : 0;
    }

    /**
     * Applies the invalidations pending for this store's release (PendingInvalidations). True
     * when none is pending afterwards; false while the index can't be reached.
     */
    public function applyPending(): bool
    {
        if (!PendingInvalidations::exist($this->store->releaseDir())) {
            return true;
        }
        if (!$this->usable()) {
            return false;
        }
        try {
            $index = $this->index($this->store);

            return $index->available() && $this->applyPendingIn($this->store, $index);
        } catch (\Throwable $e) {
            ($this->log)('page cache: pending invalidations could not be applied: ' . $e->getMessage());

            return false;
        }
    }

    private function usable(): bool
    {
        return $this->sqlite ?? extension_loaded('pdo_sqlite');
    }

    /** @param list<string> $tags */
    private function invalidateIn(PageStore $store, array $tags, float $at): void
    {
        try {
            $index = $this->index($store);
            if (!$index->available()) {
                throw new \RuntimeException('the index is unavailable');
            }
            $this->applyPendingIn($store, $index);
            $this->invalidateWith($store, $index, $tags, $at);
        } catch (\Throwable $e) {
            $this->defer($store, $tags, $at, $e);
        }
    }

    /**
     * Keeps a failed invalidation for later (PendingInvalidations); if even that can't be
     * written, the release's page files go, so nothing old is served.
     *
     * @param list<string> $tags
     */
    private function defer(PageStore $store, array $tags, float $at, \Throwable $reason): void
    {
        $kept = false;
        try {
            $kept = (new PendingInvalidations($store->releaseDir()))->add($tags, $at);
        } catch (\Throwable) {
        }
        if ($kept) {
            ($this->log)('page cache: an invalidation is pending (' . $reason->getMessage() . '); no page is served from the cache until it is applied');

            return;
        }
        ($this->log)('page cache: an invalidation failed (' . $reason->getMessage() . ') and could not be kept; the cached pages were removed');
        try {
            $store->clear();
        } catch (\Throwable) {
        }
    }

    /** Applies what is pending in $store's release; true when nothing is pending afterwards. */
    private function applyPendingIn(PageStore $store, TagIndex $index): bool
    {
        if (!PendingInvalidations::exist($store->releaseDir())) {
            return true;
        }
        $applied = (new PendingInvalidations($store->releaseDir()))->apply(function (array $tags, float $at) use ($store, $index): void {
            $this->invalidateWith($store, $index, $tags, $at);
        });
        if ($applied) {
            ($this->log)('page cache: pending invalidations were applied');
        }

        return $applied;
    }

    /** @param list<string> $tags */
    private function invalidateWith(PageStore $store, TagIndex $index, array $tags, float $at): void
    {
        // Recorded first: a render that started before and stores after this still refuses.
        $index->recordInvalidation($tags, $at);

        if (in_array(TagIndex::EVERYTHING, $tags, true)) {
            $pages = [];
            foreach ($index->fullPages() as $id => $page) {
                $pages[$id] = $page + ['verified' => true, 'priority' => RefreshQueue::PRIORITY_WARM];
            }
            $this->push($store, $index, $pages);
            $index->forgetAll();
            $store->clear();

            return;
        }
        $fresh = [];
        $nextVisit = [];
        foreach ($index->pagesFor($tags) as $id => $freshness) {
            match ($freshness) {
                Freshness::Fresh => $fresh[] = (string) $id,
                Freshness::NextVisit => $nextVisit[] = (string) $id,
                Freshness::Timed => null, // its time runs out instead (expiresAt)
            };
        }
        if ($fresh === [] && $nextVisit === []) {
            return;
        }
        $this->queueRebuild($store, $index, $fresh, $tags);
        if ($fresh !== []) {
            // Rows first: a page stored in between loses its file (a miss), never its rows.
            $index->forgetMany($fresh);
            foreach ($fresh as $id) {
                $store->forget($id);
            }
        }
        foreach ($nextVisit as $id) {
            $store->markStale($id);
        }
    }

    /**
     * Queues the rebuild of the fresh pages that are full pages without a query, before they
     * are forgotten (their rows give their URLs and key parts): those that showed a changed
     * record or address first. Next-visit pages are queued by their stale hit.
     *
     * @param list<string> $fresh
     * @param list<string> $tags
     */
    private function queueRebuild(PageStore $store, TagIndex $index, array $fresh, array $tags): void
    {
        $full = $fresh === [] ? [] : $index->fullPages($fresh);
        if ($full === []) {
            return;
        }
        $one = array_values(array_filter($tags, self::pointsAtOne(...)));
        $first = $one === [] ? [] : $index->pagesFor($one);
        $pages = [];
        foreach ($full as $id => $page) {
            $pages[$id] = $page + [
                'verified' => true,
                'priority' => isset($first[$id]) ? RefreshQueue::PRIORITY_RECORD : RefreshQueue::PRIORITY_PAGE,
            ];
        }
        $this->push($store, $index, $pages);
    }

    /** A tag of one record ({storage}:{table}#{id}) or one address (~address:...). */
    private static function pointsAtOne(string $tag): bool
    {
        if (DataTags::exact($tag)) {
            return true;
        }
        $hash = strpos($tag, '#');

        return $hash !== false && $hash < strlen($tag) - 1 && str_contains(substr($tag, 0, $hash), ':');
    }

    /** @param array<string, array{url: string, parts?: array<string, mixed>, verified?: bool, priority?: int}> $pages */
    private function push(PageStore $store, TagIndex $index, array $pages): void
    {
        if ($pages === []) {
            return;
        }
        $this->queue($store, $index)->push($pages);
        $this->queuedIn($store);
    }

    private function queuedIn(PageStore $store): void
    {
        if ($store->release() === $this->store->release() && $this->onQueue !== null) {
            ($this->onQueue)();
        }
    }

    /**
     * The key a URL is stored under by default (no locale part, no vary headers); a path is
     * resolved against the origin.
     *
     * @throws \InvalidArgumentException
     */
    private function keyOf(string $url): PageKey
    {
        $url = trim($url);
        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && $this->origin !== null) {
            $url = rtrim($this->origin, '/') . $url;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $key = $scheme === 'http' || $scheme === 'https' ? PageKey::fromRequest(new ServerRequest('GET', $url), $this->config) : null;
        if ($key === null) {
            throw new \InvalidArgumentException("Not a URL the page cache caches: '{$url}'.");
        }

        return $key;
    }

    private function index(PageStore $store): TagIndex
    {
        return $this->indexes[$store->release()] ??= TagIndex::forStore($store, $this->config, $this->clock, $this->sqlite);
    }

    private function queue(PageStore $store, TagIndex $index): RefreshQueue
    {
        $release = $store->release();
        if (!isset($this->queues[$release])) {
            $pdo = $index->pdo() ?? throw new \RuntimeException('The page cache index is unavailable.');
            $this->queues[$release] = new RefreshQueue($pdo, $this->clock);
            $this->warm($store, $this->queues[$release]);
        }

        return $this->queues[$release];
    }

    /**
     * The first time a release's queue is opened after it took over from another release
     * (PageStore::WARM_FROM): the full pages without a query of that release's index are queued,
     * last in line and verified anew (templates changed). The other index is only read (its
     * schema may be older).
     */
    private function warm(PageStore $store, RefreshQueue $queue): void
    {
        $marker = $store->releaseDir() . '/' . PageStore::WARM_FROM;
        if (!is_file($marker)) {
            return;
        }
        $previous = trim((string) @file_get_contents($marker));
        $file = dirname($store->releaseDir()) . '/' . $previous . '/' . TagIndex::FILE;
        $pages = [];
        if (preg_match('/^[0-9a-f]{16}$/', $previous) === 1 && $previous !== $store->release() && is_file($file)) {
            try {
                $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]);
                $columns = array_column($pdo->query('PRAGMA table_info(pages)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
                $parts = in_array('parts', $columns, true) ? 'parts' : "''";
                $rows = $columns === [] ? [] : $pdo->query("SELECT id, url, {$parts} FROM pages WHERE fragment = 0 AND has_query = 0")->fetchAll(\PDO::FETCH_NUM);
                foreach ($rows as [$id, $url, $json]) {
                    $pages[(string) $id] = [
                        'url' => (string) $url,
                        'parts' => TagIndex::decodeParts((string) $json),
                        'verified' => false,
                        'priority' => RefreshQueue::PRIORITY_WARM,
                    ];
                }
            } catch (\Throwable $e) {
                ($this->log)("page cache: the pages of release {$previous} could not be read to warm this one: " . $e->getMessage());
            }
        }
        if ($pages !== []) {
            $queue->push($pages);
            $this->queuedIn($store);
        }
        @unlink($marker);
    }
}
