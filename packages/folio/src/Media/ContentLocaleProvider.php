<?php

declare(strict_types=1);

namespace Preflow\Folio\Media;

use Preflow\Folio\Content\ContentLocale;
use Preflow\Core\Context\RenderContext;
use Preflow\Media\LocaleProvider;

/** Media renders alt text in Folio's content locale (the page's language, the editing locale in previews). */
final class ContentLocaleProvider implements LocaleProvider
{
    public function __construct(private readonly ContentLocale $locale) {}

    /** @since 0.81.0 */
    public function localeOf(RenderContext $ctx): string
    {
        return $this->locale->localeOf($ctx);
    }

    public function default(): string
    {
        return $this->locale->locales()->default;
    }
}
