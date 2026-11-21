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

    /** begin() was called in this frame (its key, null or not, is this frame's own). */
    public bool $began = false;

    /** The state of the frame this one is part of (a nested request). */
    public ?CacheRenderState $parent = null;

    /** The state that holds the page being rendered: this one once it began, else its parent's. */
    public function effective(): self
    {
        $state = $this;
        while (!$state->began && $state->parent !== null) {
            $state = $state->parent;
        }

        return $state;
    }
}
