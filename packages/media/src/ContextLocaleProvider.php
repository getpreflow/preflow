<?php

declare(strict_types=1);

namespace Preflow\Media;

use Preflow\Core\Context\RenderContext;

/**
 * A LocaleProvider that takes the alt-text language from the render context it is given, not
 * from state of its own. ImageTag asks localeOf(Contexts::current()) inside a render; a provider
 * without it is asked the deprecated current() there and reported once (removed in 0.83, when
 * localeOf() moves into LocaleProvider).
 *
 * ImageTag may reuse an answer for the same context object within a render frame (since 0.82.0);
 * localeOf() must depend only on $ctx and immutable configuration.
 *
 * @since 0.81.0
 */
interface ContextLocaleProvider extends LocaleProvider
{
    /** The language assets render in under $ctx (alt text), usually $ctx->locale. */
    public function localeOf(RenderContext $ctx): string;
}
