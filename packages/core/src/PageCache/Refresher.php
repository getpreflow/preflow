<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * Acts on what changed: invalidate() records the invalidation, so that a render which started
 * before and read one of the tags does not store its page (TagIndex::invalidatedSince()), and
 * then follows each page's freshness for the tags it carries (the strictest of them,
 * TagIndex::pagesFor()):
 *
 * - Fresh: the page is forgotten (its file and its index rows) and its rebuild queued;
 * - NextVisit: the page is marked stale (PageStore::markStale()): it is still served, once, while
 *   a fresh one is built;
 * - Timed: nothing. The page carries an expiresAt of its render time plus the time (set when it
 *   was stored) and refreshes then.
 *
 * So a page that read "other jobs" timed and its own job fresh is still forgotten when its job
 * changes: only the changed tags count.
 *
 * The tag TagIndex::EVERYTHING ('*') stands for every page, whatever its freshness (a deploy,
 * cache:clear, a raw write whose tables are unsure).
 *
 * The index is opened on the first invalidate() (TagIndex::forStore(), which runs the release
 * rollback check first). An unavailable index does nothing: nothing can be cached then either.
 */
final class Refresher
{
    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock microtime(true) when null
     * @param bool|null $sqlite a test seam, as TagIndex's
     */
    public function __construct(
        private readonly PageStore $store,
        private readonly PageCacheConfig $config,
        ?\Closure $clock = null,
        private readonly ?bool $sqlite = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
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
        if ($tags === [] || !($this->sqlite ?? extension_loaded('pdo_sqlite'))) {
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

    /** @param list<string> $tags */
    private function invalidateIn(PageStore $store, array $tags, float $at): void
    {
        $index = $this->index($store);
        if (!$index->available()) {
            return;
        }
        // Recorded first: a render that started before and stores after this still refuses.
        $index->recordInvalidation($tags, $at);

        if (in_array(TagIndex::EVERYTHING, $tags, true)) {
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
        $this->queueRebuild($store, $index, $fresh, $nextVisit);
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
     * Where the rebuild of the changed pages is queued, before they are forgotten or marked (their
     * rows still give their URLs): $fresh are forgotten right after, $nextVisit marked stale.
     * Nothing is queued yet; without a queue a forgotten page is rendered by its next visitor,
     * and a stale one is served once and then forgotten (PageCacheMiddleware without onStale).
     *
     * @param list<string> $fresh
     * @param list<string> $nextVisit
     */
    private function queueRebuild(PageStore $store, TagIndex $index, array $fresh, array $nextVisit): void
    {
    }

    /** @var array<string, TagIndex> by release */
    private array $indexes = [];

    private function index(PageStore $store): TagIndex
    {
        return $this->indexes[$store->release()] ??= TagIndex::forStore($store, $this->config, $this->clock, $this->sqlite);
    }
}
