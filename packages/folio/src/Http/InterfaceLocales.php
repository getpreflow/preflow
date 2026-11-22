<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Preflow\Core\Context\Contexts;
use Preflow\I18n\AcceptLanguage;
use Preflow\I18n\LocaleNames;
use Preflow\I18n\Translator;

/**
 * The languages of Folio's admin interface: those with Folio's texts delivered (lang/ of the
 * package) or in the site's lang/{locale}/folio.php. Also the default (config folio.locale,
 * else app.locale; English when that language isn't offered) and the interface locale of the
 * current render (the admin surface's, RenderContext::admin(); AdminLocaleMiddleware enters it).
 */
final class InterfaceLocales
{
    /** @param list<string>|null $locales the languages, when known (the boot manifest); else from the translator's lang files */
    public function __construct(
        private readonly Translator $translator,
        private readonly string $default = 'en',
        private readonly ?array $locales = null,
    ) {}

    /** @return list<string> two-letter codes, sorted */
    public function all(): array
    {
        return $this->locales ?? array_values(array_filter(
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

    /**
     * The interface locale of the current render: its admin surface's (Surface::$uiLocale); the
     * default on the public surface and outside every render.
     */
    public function current(): string
    {
        return $this->currentOrNull() ?? $this->default();
    }

    /** The current render's interface locale; null on the public surface and outside every render. */
    public function currentOrNull(): ?string
    {
        $surface = Contexts::peek()?->surface;

        return $surface?->admin === true ? $surface->uiLocale : null;
    }
}
