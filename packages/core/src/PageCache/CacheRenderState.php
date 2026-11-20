<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * The page a render frame renders for the cache (PageCache::begin()) and why it must not be
 * stored (PageCache::refuse()): a frame slot, so a render in a frame of its own stores nothing
 * unless it began a page itself, and what it refuses stays its own.
 *
 * @internal PageCache
 * @since 0.81.0
 */
final class CacheRenderState
{
    public ?PageKey $key = null;

    public ?string $refusal = null;
}
