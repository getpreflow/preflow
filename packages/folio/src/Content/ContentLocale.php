<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Blocks\BlockLocale;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Data\Locales;

/**
 * The locale content is rendered in: the current render's locale (RenderContext::$locale; the
 * page's locale on the frontend, the editing locale in previews) when it is one of the content
 * locales, else the default one. Field types that render referenced records (relation, matrix)
 * follow it, and so do blocks (BlockLocale). It holds no state of its own since 0.81: a render
 * in another locale is a context with ->in($locale).
 */
final class ContentLocale implements BlockLocale
{
    public function __construct(
        private readonly Locales $locales,
    ) {}

    /** The current render's locale when it is a content locale, else the default; the default outside every render. */
    public function get(): string
    {
        $locale = Contexts::peek()?->locale;

        return $locale === null ? $this->locales->default : $this->content($locale);
    }

    /**
     * The locale blocks render in under $ctx: its locale when it is a content locale, else the
     * default one.
     *
     * @since 0.81.0
     */
    public function localeOf(RenderContext $ctx): string
    {
        return $this->content($ctx->locale);
    }

    public function locales(): Locales
    {
        return $this->locales;
    }

    /** $locale when it is a content locale, else the default one. */
    private function content(string $locale): string
    {
        return $this->locales->has($locale) ? $locale : $this->locales->default;
    }
}
