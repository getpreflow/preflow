<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Core\Context\RenderContext;
use Preflow\Data\Locales;

/**
 * The locale blocks render in: translatable block fields (locale maps) are resolved to it
 * before a block's component gets its props. It takes the locale from the render context it is
 * given, not from state of its own (BlockRenderer asks localeOf(Contexts::current())).
 * DefaultBlockLocale follows the render context's locale; an app (or Folio) binds its own
 * content locale.
 *
 * Since 0.83 localeOf() is part of this interface (it was ContextBlockLocale's in 0.81 and
 * 0.82) and get() is gone.
 */
interface BlockLocale
{
    /**
     * The locale blocks render in under $ctx: one of locales(), usually $ctx->locale or the default.
     *
     * @since 0.81.0 (on ContextBlockLocale; on BlockLocale since 0.83.0)
     */
    public function localeOf(RenderContext $ctx): string;

    /** The content locales (what a locale map can hold, and the default to fall back to). */
    public function locales(): Locales;
}
