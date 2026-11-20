<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Data\Locales;

/**
 * The locale blocks render in: translatable block fields (locale maps) are resolved to it
 * before a block's component gets its props. DefaultBlockLocale follows the render context's
 * locale; an app (or Folio) binds its own content locale.
 *
 * Since 0.81 an implementation also implements ContextBlockLocale, which takes the render
 * context; in 0.83 its localeOf() moves here and get() goes.
 */
interface BlockLocale
{
    /**
     * The locale to render in now.
     *
     * @deprecated 0.81, removed in 0.83: implement ContextBlockLocale::localeOf(RenderContext $ctx);
     *             BlockRenderer asks get() only of an implementation without it
     */
    public function get(): string;

    /** The content locales (what a locale map can hold, and the default to fall back to). */
    public function locales(): Locales;
}
