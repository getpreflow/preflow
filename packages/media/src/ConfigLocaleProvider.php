<?php

declare(strict_types=1);

namespace Preflow\Media;

use Preflow\Core\Context\RenderContext;

/**
 * The default LocaleProvider: the render context's locale (since 0.81, localeOf()); outside every
 * render current() gives the i18n Translator's locale when the app has one, else config/app.php
 * "locale", else "en". The default is the app locale.
 */
final class ConfigLocaleProvider implements ContextLocaleProvider
{
    /** @param \Closure(): ?string $translatorLocale the Translator's current locale, null without one */
    public function __construct(
        private readonly \Closure $translatorLocale,
        private readonly string $appLocale = 'en',
    ) {}

    /** @since 0.81.0 */
    public function localeOf(RenderContext $ctx): string
    {
        return $ctx->locale !== '' ? $ctx->locale : $this->appLocale;
    }

    /** @deprecated 0.81, removed in 0.83 inside a render: localeOf(Contexts::current()) */
    public function current(): string
    {
        $locale = ($this->translatorLocale)();

        return is_string($locale) && $locale !== '' ? $locale : $this->appLocale;
    }

    public function default(): string
    {
        return $this->appLocale;
    }
}
