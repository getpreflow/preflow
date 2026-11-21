<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\RenderContext;
use Preflow\Data\Locales;

/**
 * The BlockLocale without an app of its own: the render context's locale when it is a content
 * locale, else the default. Since 0.81 it reads the context, not the translator.
 */
final class DefaultBlockLocale implements ContextBlockLocale
{
    /** @param Locales $locales the content locales; a second argument (the translator's locale before 0.81) is ignored */
    public function __construct(
        private readonly Locales $locales,
    ) {}

    /** @since 0.81.0 */
    public function localeOf(RenderContext $ctx): string
    {
        return $this->locales->has($ctx->locale) ? $ctx->locale : $this->locales->default;
    }

    /**
     * @deprecated 0.81, removed in 0.83: localeOf(Contexts::current())
     * @throws \Preflow\Core\Context\NoRenderContext outside every render
     */
    public function get(): string
    {
        Deprecations::once('Preflow\Blocks\DefaultBlockLocale::get() is deprecated since 0.81 and is removed in 0.83; use DefaultBlockLocale::localeOf(Contexts::current()).');

        return $this->localeOf(Contexts::current());
    }

    public function locales(): Locales
    {
        return $this->locales;
    }
}
