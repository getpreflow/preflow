<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Core\Context\RenderContext;

/**
 * A BlockLocale that takes the locale from the render context it is given, not from state of
 * its own. BlockRenderer asks localeOf(Contexts::current()); an implementation without it is
 * asked the deprecated get() and reported once (removed in 0.83, when localeOf() moves into
 * BlockLocale).
 *
 * @since 0.81.0
 */
interface ContextBlockLocale extends BlockLocale
{
    /** The locale blocks render in under $ctx: one of locales(), usually $ctx->locale or the default. */
    public function localeOf(RenderContext $ctx): string;
}
