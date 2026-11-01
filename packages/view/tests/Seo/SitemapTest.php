<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Seo;

use PHPUnit\Framework\TestCase;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\Sitemap;

final class SitemapTest extends TestCase
{
    public function test_alternates_and_x_default(): void
    {
        $page = new PublicPage('Home', '/', lastmod: '2026-01-02T03:04:05+00:00', alternates: ['en' => '/', 'de' => '/de']);
        $xml = Sitemap::xml(['en' => [$page]], 'https://example.com/', 'en');

        $this->assertSame(<<<'X'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
  <url>
    <loc>https://example.com/</loc>
    <lastmod>2026-01-02T03:04:05+00:00</lastmod>
    <xhtml:link rel="alternate" hreflang="en" href="https://example.com/"/>
    <xhtml:link rel="alternate" hreflang="de" href="https://example.com/de"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="https://example.com/"/>
  </url>
</urlset>

X, $xml);
    }

    public function test_no_lastmod_and_no_alternates_when_unset(): void
    {
        $xml = Sitemap::xml(['en' => [new PublicPage('A', 'https://example.com/a')]], 'https://example.com', 'en');

        $this->assertStringNotContainsString('lastmod', $xml);
        $this->assertStringNotContainsString('xhtml:link rel', $xml);
        $this->assertStringContainsString('<loc>https://example.com/a</loc>', $xml);
    }

    public function test_escapes_urls(): void
    {
        $xml = Sitemap::xml(['en' => [new PublicPage('A', '/a?x=1&y=2')]], 'https://example.com', 'en');

        $this->assertStringContainsString('<loc>https://example.com/a?x=1&amp;y=2</loc>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_only_sitemap_channel_and_deduplicated_by_url(): void
    {
        $xml = Sitemap::xml([
            'en' => [new PublicPage('A', '/a'), new PublicPage('Hidden', '/hidden', sitemap: false)],
            'de' => [new PublicPage('A', '/a'), new PublicPage('B', '/de/b')],
        ], 'https://example.com', 'en');

        $this->assertSame(2, substr_count($xml, '<url>'));
        $this->assertStringNotContainsString('/hidden', $xml);
        $this->assertStringContainsString('/de/b', $xml);
    }
}
