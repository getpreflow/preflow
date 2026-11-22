<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Core\Context\RenderContext;
use Preflow\Data\Locales;

/**
 * The BlockLocale without an app of its own: the render context's locale when it is a content
 * locale, else the default. Since 0.81 it reads the context, not the translator.
 */
final class DefaultBlockLocale implements BlockLocale
{
    /** @param Locales $locales the content locales */
    public function __construct(
        private readonly Locales $locales,
    ) {}

    /** @since 0.81.0 */
    public function localeOf(RenderContext $ctx): string
    {
        return $this->locales->has($ctx->locale) ? $ctx->locale : $this->locales->default;
    }

    public function locales(): Locales
    {
        return $this->locales;
    }
}
