<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Data\Locales;

/**
 * The locale blocks render in: translatable block fields (locale maps) are resolved to it
 * before a block's component gets its props. DefaultBlockLocale follows the request's
 * interface locale; an app (or Folio) binds its own content locale.
 */
interface BlockLocale
{
    /** The locale to render in now. */
    public function get(): string;

    /** The content locales (what a locale map can hold, and the default to fall back to). */
    public function locales(): Locales;

    /**
     * Run $render and put the locale back afterwards (also when it throws), so an
     * internal render can't change the request's.
     *
     * @template T
     * @param callable(): T $render
     * @return T
     */
    public function scoped(callable $render): mixed;
}
