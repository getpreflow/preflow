<?php

declare(strict_types=1);

namespace Preflow\I18n;

use Preflow\Core\Context\RenderContext;

/**
 * The translator's text caches as one value, so they are always dropped together (forgetMemos()
 * replaces the whole holder): the caches by text locale and overrides switch, and which of them
 * each render context found.
 *
 * @internal Translator
 *
 * @since 0.82.0
 */
final class CatalogueMemos
{
    /**
     * "{locale}" or "{locale}\x01" (overrides on) => its texts, for keys no override can answer.
     * Contexts with the same text locale and overrides switch share one.
     *
     * @var array<string, CatalogueMemo>
     */
    public array $byKey = [];

    /**
     * The cache each render context found in $byKey (Translator::memoOf()), by the context object:
     * a t() finds its context's cache without working out the key again. A context is immutable,
     * so its entry never goes stale while the holder lives; an entry goes with its context.
     *
     * @var \WeakMap<RenderContext, CatalogueMemo>
     */
    public readonly \WeakMap $byContext;

    public function __construct()
    {
        $this->byContext = new \WeakMap();
    }
}
