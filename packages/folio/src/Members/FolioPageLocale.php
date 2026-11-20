<?php

declare(strict_types=1);

namespace Preflow\Folio\Members;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Members\PageLocale;

/**
 * A member page's locale is Folio's content locale too, so the site layout renders its content
 * in it: the rest of the member page's frame renders in $locale (a content locale, else the
 * default one).
 */
final class FolioPageLocale implements PageLocale
{
    public function __construct(
        private readonly ContentLocale $contentLocale,
    ) {}

    /** @throws \Preflow\Core\Context\NoRenderContext outside every render */
    public function set(string $locale): void
    {
        $locales = $this->contentLocale->locales();
        $locale = $locales->has($locale) ? $locale : $locales->default;
        Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->in($locale));
    }
}
