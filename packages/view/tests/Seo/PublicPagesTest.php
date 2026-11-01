<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Seo;

use PHPUnit\Framework\TestCase;
use Preflow\View\Geo\GeoEntries;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\PublicPages;

final class PublicPagesTest extends TestCase
{
    public function test_channels_are_subsets_of_for(): void
    {
        $pages = new PublicPages();
        $pages->source(fn (string $l) => [
            new PublicPage('A', '/a'),
            new PublicPage('B', '/b', sitemap: false),
            new PublicPage('C', '/c', llms: false),
        ]);

        $this->assertSame(['A', 'B', 'C'], array_map(fn ($p) => $p->title, $pages->for('en')));
        $this->assertSame(['A', 'C'], array_map(fn ($p) => $p->title, $pages->sitemap('en')));
        $this->assertSame(['A', 'B'], array_map(fn ($p) => $p->title, $pages->llms('en')));
    }

    public function test_sorts_home_section_first_then_order_then_title(): void
    {
        $pages = new PublicPages();
        $pages->source(fn (string $l) => [
            new PublicPage('Zed', '/z', section: 'Docs', order: 1),
            new PublicPage('Beta', '/b', section: 'Pages'),
            new PublicPage('Alpha', '/a', section: 'Pages'),
            new PublicPage('Home', '/', section: 'Main', order: 0),
        ]);

        $this->assertSame(['Home', 'Zed', 'Alpha', 'Beta'], array_map(fn ($p) => $p->title, $pages->for('en')));
    }

    public function test_filters_run_after_sources(): void
    {
        $pages = new PublicPages();
        $pages->source(fn (string $l) => [new PublicPage('A', '/a'), new PublicPage('B', '/b')]);
        $pages->filter(fn (array $list, string $l) => array_filter($list, fn ($p) => $p->title !== 'A'));

        $this->assertSame(['B'], array_map(fn ($p) => $p->title, $pages->for('en')));
    }

    public function test_sources_and_filters_must_give_public_pages(): void
    {
        $pages = new PublicPages();
        $pages->source(fn (string $l) => ['nope']);

        $this->expectException(\UnexpectedValueException::class);
        $pages->for('en');
    }

    public function test_with_changes_named_arguments(): void
    {
        $page = new PublicPage('A', '/a', lastmod: '2026-01-01T00:00:00+00:00');
        $changed = $page->with(['title' => 'B', 'llms' => false]);

        $this->assertSame('B', $changed->title);
        $this->assertFalse($changed->llms);
        $this->assertSame('/a', $changed->url);
        $this->assertSame('2026-01-01T00:00:00+00:00', $changed->lastmod);
        $this->assertSame('/x', $page->withUrl('/x')->url);
        $this->assertSame('A', $page->title);
    }

    public function test_geo_entries_read_the_llms_channel(): void
    {
        $pages = new PublicPages();
        $pages->source(fn (string $l) => [
            new PublicPage('In', '/in', 'desc', 'Docs', 5, llmsFull: false),
            new PublicPage('Out', '/out', llms: false),
        ]);
        $entries = (new GeoEntries($pages))->for('en');

        $this->assertCount(1, $entries);
        $this->assertSame('In', $entries[0]->title);
        $this->assertSame('Docs', $entries[0]->section);
        $this->assertSame(5, $entries[0]->order);
        $this->assertFalse($entries[0]->full);
    }
}
