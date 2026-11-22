<?php

declare(strict_types=1);

namespace Preflow\Media;

use Preflow\Core\Context\RenderContext;

/**
 * The language assets render in (alt text): the one for a render context, and the default to
 * fall back to. It takes the language from the render context it is given, not from state of its
 * own: ImageTag asks localeOf(Contexts::current()) inside a render and default() outside every
 * render (a script, a unit test).
 *
 * ImageTag may reuse an answer for the same context object within a render frame (since 0.82.0);
 * localeOf() must depend only on $ctx and immutable configuration.
 *
 * Since 0.83 localeOf() is part of this interface (it was ContextLocaleProvider's in 0.81 and
 * 0.82) and current() is gone.
 */
interface LocaleProvider
{
    /**
     * The language assets render in under $ctx (alt text), usually $ctx->locale.
     *
     * @since 0.81.0 (on ContextLocaleProvider; on LocaleProvider since 0.83.0)
     */
    public function localeOf(RenderContext $ctx): string;

    public function default(): string;
}
