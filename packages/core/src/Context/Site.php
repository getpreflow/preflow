<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

use Preflow\Core\Http\LocaleUrls;

/**
 * The site being rendered: its origin (app.url), its locales and default locale, and its locale
 * URL rule. The one value a future multi-site setup varies.
 *
 * @since 0.81.0
 */
final readonly class Site
{
    /**
     * @param string $origin e.g. 'https://example.com'
     * @param list<string> $locales every available locale
     * @param LocaleUrls|null $urls null: no locale prefixes in URLs
     */
    public function __construct(
        public string $origin,
        public array $locales,
        public string $defaultLocale,
        public ?LocaleUrls $urls = null,
    ) {}

    /** The URL prefix of $locale ('/de'), '' without one. */
    public function prefixFor(string $locale): string
    {
        return $this->urls?->prefix($locale) ?? '';
    }
}
