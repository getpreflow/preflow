<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Severity;
use Preflow\View\Seo\PublicPages;

/**
 * `noindex.in-sitemap` (critical): the page asks search engines not to index it
 * (a robots/googlebot meta tag or an X-Robots-Tag header with noindex or none),
 * yet the sitemap lists it. The sitemap's pages are read once per locale, and only
 * when a page is noindex.
 *
 * @since 0.54
 */
final class NoindexInSitemap implements PageCheck
{
    /** @var array<string, array<string, true>> locale => URL keys the sitemap lists */
    private array $sitemap = [];

    /** @param list<string> $locales the site's locales (a page without one is looked up in each) */
    public function __construct(
        private readonly AuditRegistry $registry,
        private readonly PublicPages $pages,
        private readonly array $locales,
    ) {}

    public function id(): string
    {
        return 'noindex.in-sitemap';
    }

    public function check(RenderedPage $page): array
    {
        if (!self::noindex($page)) {
            return [];
        }

        $key = Urls::key($page->url);
        foreach ($page->locale !== '' ? [$page->locale] : $this->locales as $locale) {
            if (isset($this->sitemap($locale, $page->url)[$key])) {
                return [Html::pageFinding($this->registry, $page, $this->id(), Severity::Critical,
                    'The page says noindex but the sitemap lists it; take it out of the sitemap or let search engines index it.')];
            }
        }

        return [];
    }

    private static function noindex(RenderedPage $page): bool
    {
        $directives = [$page->header('X-Robots-Tag')];
        $dom = $page->dom();
        if ($dom !== null) {
            foreach ($dom->getElementsByTagName('meta') as $meta) {
                if (in_array(strtolower(trim($meta->getAttribute('name'))), ['robots', 'googlebot'], true)) {
                    $directives[] = $meta->getAttribute('content');
                }
            }
        }

        return preg_match('/(^|[\s,:])(noindex|none)\b/i', implode(',', $directives)) === 1;
    }

    /** @return array<string, true> */
    private function sitemap(string $locale, string $base): array
    {
        if (!isset($this->sitemap[$locale])) {
            $this->sitemap[$locale] = [];
            foreach ($this->pages->sitemap($locale) as $listed) {
                $url = Urls::resolve($listed->url, $base);
                if ($url !== null) {
                    $this->sitemap[$locale][Urls::key($url)] = true;
                }
            }
        }

        return $this->sitemap[$locale];
    }
}
