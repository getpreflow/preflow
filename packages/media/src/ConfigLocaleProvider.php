<?php

declare(strict_types=1);

namespace Preflow\Media;

use Preflow\Core\Context\RenderContext;

/**
 * The default LocaleProvider: the render context's locale (since 0.81), else the app locale
 * (config/app.php "locale", else "en"), which is also the default.
 */
final class ConfigLocaleProvider implements LocaleProvider
{
    /** @param string $appLocale config/app.php "locale" (since 0.83.0 the only argument; the translator's locale closure before it is gone) */
    public function __construct(
        private readonly string $appLocale = 'en',
    ) {}

    /** @since 0.81.0 */
    public function localeOf(RenderContext $ctx): string
    {
        return $ctx->locale !== '' ? $ctx->locale : $this->appLocale;
    }

    public function default(): string
    {
        return $this->appLocale;
    }
}
