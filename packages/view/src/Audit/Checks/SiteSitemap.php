<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\Severity;
use Preflow\View\Audit\SiteCheck;
use Preflow\View\Seo\PublicPages;
use Preflow\View\Seo\SeoConfig;

/**
 * The sitemap, when SEO is on (with SEO off it finds nothing):
 *
 * - `sitemap.missing` (warning): /sitemap.xml answers neither from the app nor from public/;
 * - `sitemap.invalid` (critical): it, or a sitemap its index lists, isn't valid XML with a `<urlset>` or
 *   `<sitemapindex>`;
 * - `sitemap.non200` (critical): a URL it lists on this site doesn't answer 200. The audit's public pages
 *   aren't rendered again: their own status finding stands for them.
 *
 * @since 0.54
 */
final class SiteSitemap implements SiteCheck
{
    public function __construct(
        private readonly SeoConfig $seo,
        private readonly PublicPages $pages,
        private readonly ?string $publicDir = null,
    ) {}

    public function id(): string
    {
        return 'sitemap';
    }

    public function check(PageRenderer $renderer): array
    {
        if (!$this->seo->enabled()) {
            return [];
        }

        $url = $renderer->origin() . '/sitemap.xml';
        $xml = SiteFiles::read($renderer, $this->publicDir, '/sitemap.xml');
        if ($xml === null) {
            return [new Finding('sitemap.missing', Severity::Warning,
                "/sitemap.xml doesn't answer, so search engines have no list of the site's pages. Turn the sitemap on in config/seo.php or put one in public/.",
                $url)];
        }

        $findings = [];
        $listed = [];
        $host = Urls::host($renderer->origin());
        $sitemaps = [$url => $xml];
        $read = [];
        while ($sitemaps !== []) {
            $sitemapUrl = array_key_first($sitemaps);
            $content = $sitemaps[$sitemapUrl];
            unset($sitemaps[$sitemapUrl]);
            $read[$sitemapUrl] = true;

            [$root, $locs] = self::parse($content);
            if ($root === null) {
                $findings[] = new Finding('sitemap.invalid', Severity::Critical,
                    "The sitemap at {$sitemapUrl} isn't valid XML with a <urlset> or <sitemapindex>, so search engines can't read it.",
                    $sitemapUrl);
                continue;
            }
            foreach ($locs as $loc) {
                $loc = Urls::resolve($loc, $sitemapUrl);
                if ($loc === null || Urls::host($loc) !== $host) {
                    continue;
                }
                if ($root === 'urlset') {
                    $listed[Urls::key($loc)] ??= $loc;
                } elseif (!isset($read[$loc]) && !isset($sitemaps[$loc])) {
                    // A sitemap the index lists; one that doesn't answer is a URL that isn't 200.
                    $child = SiteFiles::read($renderer, $this->publicDir, Urls::path($loc));
                    if ($child === null) {
                        $listed[Urls::key($loc)] ??= $loc;
                    } else {
                        $sitemaps[$loc] = $child;
                    }
                }
            }
        }

        $public = $this->publicKeys($renderer);
        foreach ($listed as $key => $loc) {
            if (isset($public[$key]) || SiteFiles::publicFile($this->publicDir, Urls::path($loc)) !== null) {
                continue;
            }
            $page = $renderer->render(Urls::path($loc));
            if ($page->status !== 200) {
                $findings[] = new Finding('sitemap.non200', Severity::Critical,
                    "The sitemap lists {$loc}, which answers {$page->status} instead of 200. List only pages that answer, or fix the page.",
                    $loc, $page->locale);
            }
        }

        return $findings;
    }

    /**
     * The root element's name ('urlset' or 'sitemapindex') and the <loc> values; [null, []] when it isn't one.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private static function parse(string $xml): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $parsed = trim($xml) !== '' && $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $parsed ? $document->documentElement : null;
        if ($root === null || !in_array($root->localName, ['urlset', 'sitemapindex'], true)) {
            return [null, []];
        }

        // Only <url><loc> or <sitemap><loc>: an image sitemap's <image:loc> names an image, not a page.
        $entry = $root->localName === 'urlset' ? 'url' : 'sitemap';
        $locs = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $entry) {
                foreach ($child->childNodes as $loc) {
                    if ($loc instanceof \DOMElement && $loc->localName === 'loc') {
                        $locs[] = trim($loc->textContent);
                    }
                }
            }
        }

        return [$root->localName, $locs];
    }

    /** @return array<string, true> URL keys of the pages the audit renders itself */
    private function publicKeys(PageRenderer $renderer): array
    {
        $keys = [];
        foreach ($renderer->locales() as $locale) {
            foreach ($this->pages->for($locale) as $page) {
                $url = Urls::resolve($page->url, $renderer->origin() . '/');
                if ($url !== null) {
                    $keys[Urls::key($url)] = true;
                }
            }
        }

        return $keys;
    }
}
