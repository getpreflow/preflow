<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * RenderContext::memoKey() per context object, computed once: a context is immutable, so its key
 * never changes, and the memos of a render ask for it on every read. A WeakMap: an entry goes
 * with its context, so nothing is kept after a render and nothing of one context reaches another.
 *
 * @internal RenderContext
 *
 * @since 0.81.0
 */
final class MemoKeys
{
    /** @var \WeakMap<RenderContext, string>|null */
    private static ?\WeakMap $keys = null;

    public static function of(RenderContext $ctx): string
    {
        $keys = self::$keys ??= new \WeakMap();

        return $keys[$ctx] ??= $ctx->computeMemoKey();
    }
}
