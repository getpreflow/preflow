<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * Acts on what changed: invalidate() forgets every cached page carrying one of the tags (its
 * file and its index rows) and records the invalidation, so that a render which started before
 * and read one of them does not store its page (TagIndex::invalidatedSince()).
 *
 * The tag TagIndex::EVERYTHING ('*') stands for every page.
 *
 * The index is opened on the first invalidate() (TagIndex::forStore(), which runs the release
 * rollback check first). An unavailable index does nothing: nothing can be cached then either.
 */
final class Refresher
{
    private ?TagIndex $index = null;

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

    /** @param list<string> $tags what changed (DataTags::changes() applied) */
    public function invalidate(array $tags): void
    {
        $tags = array_values(array_unique(array_map(strval(...), $tags)));
        if ($tags === [] || !($this->sqlite ?? extension_loaded('pdo_sqlite'))) {
            return; // without pdo_sqlite nothing is cached: no store activation, no file touched
        }
        $index = $this->index();
        if (!$index->available()) {
            return;
        }
        // Recorded first: a render that started before and stores after this still refuses.
        $index->recordInvalidation($tags, ($this->clock)());

        if (in_array(TagIndex::EVERYTHING, $tags, true)) {
            $index->forgetAll();
            $this->store->clear();

            return;
        }
        $this->forget(array_keys($index->pagesFor($tags)));
    }

    /** @param list<string> $pageIds */
    private function forget(array $pageIds): void
    {
        if ($pageIds === []) {
            return;
        }
        // Rows first: a page stored in between loses its file (a miss), never its rows.
        $this->index()->forgetMany($pageIds);
        foreach ($pageIds as $id) {
            $this->store->forget((string) $id);
        }
    }

    private function index(): TagIndex
    {
        return $this->index ??= TagIndex::forStore($this->store, $this->config, $this->clock, $this->sqlite);
    }
}
