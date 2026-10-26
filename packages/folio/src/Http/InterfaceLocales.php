<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Preflow\I18n\AcceptLanguage;
use Preflow\I18n\LocaleNames;
use Preflow\I18n\Translator;

/**
 * The languages of Folio's admin interface: those with Folio's texts delivered (lang/ of the
 * package) or in the site's lang/{locale}/folio.php. Also the default (config folio.locale,
 * else app.locale; English when that language isn't offered) and the locale of the current
 * admin request (AdminLocaleMiddleware sets it).
 */
final class InterfaceLocales
{
    private ?string $current = null;

    public function __construct(
        private readonly Translator $translator,
        private readonly string $default = 'en',
    ) {}

    /** @return list<string> two-letter codes, sorted */
    public function all(): array
    {
        return array_values(array_filter(
            $this->translator->locales('folio'),
            static fn (string $locale): bool => preg_match('/^[a-z]{2}$/', $locale) === 1,
        ));
    }

    /** @return array<string, string> code => the language's own name ("Deutsch"), for the switch */
    public function names(): array
    {
        $names = [];
        foreach ($this->all() as $locale) {
            $names[$locale] = LocaleNames::native($locale);
        }

        return $names;
    }

    public function offers(string $locale): bool
    {
        return in_array($locale, $this->all(), true);
    }

    public function default(): string
    {
        return $this->offers($this->default) ? $this->default : 'en';
    }

    /** The browser's best offered language (Accept-Language), else the default. */
    public function forBrowser(string $acceptLanguage): string
    {
        return AcceptLanguage::best($acceptLanguage, $this->all()) ?? $this->default();
    }

    /** The interface locale of the admin request being handled (the default outside one). */
    public function current(): string
    {
        return $this->current ?? $this->default();
    }

    /** @internal AdminLocaleMiddleware: entering (a locale) and leaving (the one before) an admin request */
    public function setCurrent(?string $locale): void
    {
        $this->current = $locale;
    }

    /** @internal for restoring */
    public function currentOrNull(): ?string
    {
        return $this->current;
    }
}
