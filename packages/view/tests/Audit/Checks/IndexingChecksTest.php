<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit\Checks;

require_once __DIR__ . '/CheckFixtures.php';

use PHPUnit\Framework\TestCase;
use Preflow\View\Audit\Checks\CanonicalForeignHost;
use Preflow\View\Audit\Checks\CanonicalMissing;
use Preflow\View\Audit\Checks\NoindexInSitemap;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\PublicPages;

/** The page checks for what search engines index: canonical, noindex against the sitemap. */
final class IndexingChecksTest extends TestCase
{
    use CheckFixtures;

    public function test_canonical_missing(): void
    {
        $check = new CanonicalMissing($this->registry());

        $this->assertSame('canonical.missing', $check->id());
        $this->assertSame([], $check->check($this->page($this->html('<link rel="canonical" href="/about">'))));

        foreach (['', '<link rel="canonical" href="">', '<link rel="alternate" href="/de/about" hreflang="de">'] as $head) {
            $findings = $check->check($this->page($this->html($head)));
            $this->assertSame(['canonical.missing|notice|https://site.test/about|en'], $this->summary($findings));
            $this->assertTargetsNamePages($findings);
        }
    }

    public function test_canonical_foreign_host_passes_the_sites_own_host_and_relative_urls(): void
    {
        $check = new CanonicalForeignHost($this->registry(), 'https://site.test');

        $this->assertSame('canonical.foreign-host', $check->id());
        foreach (['', '<link rel="canonical" href="https://SITE.test/about">', '<link rel="canonical" href="/about">', '<link rel="canonical" href="http://site.test/about">'] as $head) {
            $this->assertSame([], $check->check($this->page($this->html($head))), $head);
        }
    }

    public function test_canonical_foreign_host_reports_another_host(): void
    {
        $check = new CanonicalForeignHost($this->registry(), 'https://site.test');

        foreach (['https://staging.site.test/about', '//other.test/about'] as $href) {
            $findings = $check->check($this->page($this->html("<link rel=\"canonical\" href=\"{$href}\">")));
            $this->assertSame(['canonical.foreign-host|warning|https://site.test/about|en'], $this->summary($findings));
            $this->assertStringContainsString('site.test', $findings[0]->message);
            $this->assertTargetsNamePages($findings);
        }
    }

    public function test_noindex_in_sitemap_passes_indexable_pages_and_noindex_pages_kept_out_of_the_sitemap(): void
    {
        $asked = 0;
        $check = new NoindexInSitemap($this->registry(), $this->publicPages($asked), ['en', 'de']);

        $this->assertSame('noindex.in-sitemap', $check->id());
        $this->assertSame([], $check->check($this->page($this->html('<meta name="robots" content="index, follow">'))));
        $this->assertSame(0, $asked, 'an indexable page needs no sitemap lookup');
        $this->assertSame([], $check->check($this->page($this->html('<meta name="robots" content="noindex">'), 'https://site.test/hidden')));
    }

    public function test_noindex_in_sitemap_reports_a_noindex_page_the_sitemap_lists(): void
    {
        $asked = 0;
        $check = new NoindexInSitemap($this->registry(), $this->publicPages($asked), ['en', 'de']);

        $byMeta = $check->check($this->page($this->html('<meta name="ROBOTS" content="NoIndex, follow">')));
        $byNone = $check->check($this->page($this->html('<meta name="googlebot" content="none">')));
        $byHeader = $check->check($this->page($this->html(), 'https://site.test/de/%C3%BCber', 'de', ['X-Robots-Tag' => ['noindex']]));

        $this->assertSame(['noindex.in-sitemap|critical|https://site.test/about|en'], $this->summary($byMeta));
        $this->assertSame(['noindex.in-sitemap|critical|https://site.test/about|en'], $this->summary($byNone));
        $this->assertSame(['noindex.in-sitemap|critical|https://site.test/de/%C3%BCber|de'], $this->summary($byHeader));
        $this->assertTargetsNamePages([...$byMeta, ...$byNone, ...$byHeader]);
        $this->assertSame(2, $asked, 'the sitemap is read once per locale');
    }

    private function publicPages(int &$asked): PublicPages
    {
        $pages = new PublicPages();
        $pages->source(static function (string $locale) use (&$asked): array {
            $asked++;

            return match ($locale) {
                'en' => [new PublicPage('About', 'https://site.test/about'), new PublicPage('Hidden', '/hidden', sitemap: false)],
                'de' => [new PublicPage('Über uns', '/de/über')],
                default => [],
            };
        });

        return $pages;
    }
}
