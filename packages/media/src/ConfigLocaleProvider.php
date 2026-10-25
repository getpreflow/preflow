<?php

declare(strict_types=1);

namespace Preflow\Media;

/**
 * The default LocaleProvider: the i18n Translator's locale when the app has
 * one, else config/app.php "locale", else "en". The default is the app locale.
 */
final class ConfigLocaleProvider implements LocaleProvider
{
    /** @param \Closure(): ?string $translatorLocale the Translator's current locale, null without one */
    public function __construct(
        private readonly \Closure $translatorLocale,
        private readonly string $appLocale = 'en',
    ) {}

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
