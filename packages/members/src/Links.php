<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Core\Http\LocaleUrls;
use Preflow\Data\Locales;

/**
 * Absolute links for mail, from config app.url, never from the request Host: the member
 * pages under {@see MembersConfig::path()}, prefixed with the locale by the site's one rule
 * ({@see LocaleUrls}: the default locale at the root unless prefix_default is on, no prefix
 * with url_strategy "none").
 */
final class Links
{
    private readonly string $base;

    /** @param LocaleUrls $urls the site's locale URL rule; core binds it before any provider registers */
    public function __construct(
        string $baseUrl,
        private readonly string $path,
        private readonly Locales $locales,
        private readonly LocaleUrls $urls,
    ) {
        $this->base = rtrim($baseUrl, '/');
    }

    /** A member page in $locale (an unknown locale gets the default), e.g. url('de', '/login'). */
    public function url(string $locale, string $path = ''): string
    {
        return $this->base . $this->local($locale, $path);
    }

    /** The same page without the host, for links inside the site: "/de/members/login". */
    public function local(string $locale, string $path = ''): string
    {
        $locale = $this->locales->has($locale) ? $locale : $this->locales->default;
        return $this->prefix($locale) . $this->path . $path;
    }

    /** The locale prefix of a site path ("/de/start" for "/start"), as LocaleUrls gives it. */
    public function localize(string $locale, string $path): string
    {
        $locale = $this->locales->has($locale) ? $locale : $this->locales->default;

        return $this->prefix($locale) . $path;
    }

    private function prefix(string $locale): string
    {
        return $this->urls->prefix($locale);
    }

    /** @return list<string> the site's locales, in the order config/i18n.php lists them */
    public function locales(): array
    {
        return array_values($this->locales->available);
    }
}
