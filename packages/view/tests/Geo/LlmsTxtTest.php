<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Geo;

use PHPUnit\Framework\TestCase;
use Preflow\View\Geo\GeoEntries;
use Preflow\View\Geo\GeoEntry;
use Preflow\View\Geo\LlmsTxt;

final class LlmsTxtTest extends TestCase
{
    public function test_index_lists_sections_of_markdown_links(): void
    {
        $txt = (new LlmsTxt())->index('Site', 'What the site is about.', [
            new GeoEntry('Home', 'https://site.test/', 'The start page', order: 0),
            new GeoEntry('About [us]', 'https://site.test/about'),
            new GeoEntry('Chess', 'https://site.test/games/chess', "Two players,\n  one board", section: 'Games'),
        ], []);

        $this->assertSame(
            "# Site\n\n> What the site is about.\n\n"
            . "## Pages\n\n"
            . "- [Home](https://site.test/index.md): The start page\n"
            . "- [About \\[us\\]](https://site.test/about.md)\n\n"
            . "## Games\n\n"
            . "- [Chess](https://site.test/games/chess.md): Two players, one board\n",
            $txt,
        );
    }

    public function test_index_of_the_default_locale_links_the_other_languages(): void
    {
        $txt = (new LlmsTxt())->index('Site', '', [new GeoEntry('Home', 'https://site.test/de/', order: 0)], [
            ['locale' => 'de', 'url' => 'https://site.test/de/llms.txt'],
            ['locale' => 'fr', 'url' => 'https://site.test/fr/llms.txt'],
        ]);

        $this->assertSame(
            "# Site\n\n"
            . "## Pages\n\n"
            . "- [Home](https://site.test/de/index.md)\n\n"
            . "## Other languages\n\n"
            . "- [de](https://site.test/de/llms.txt)\n"
            . "- [fr](https://site.test/fr/llms.txt)\n",
            $txt,
        );
    }

    public function test_the_other_languages_heading_is_given_in_the_files_language(): void
    {
        $txt = (new LlmsTxt())->index('Seite', '', [], [
            ['locale' => 'en', 'url' => 'https://site.test/en/llms.txt'],
        ], 'Andere Sprachen');

        $this->assertSame("# Seite\n\n## Andere Sprachen\n\n- [en](https://site.test/en/llms.txt)\n", $txt);
    }

    public function test_index_without_entries_is_the_header_alone(): void
    {
        $this->assertSame("# Site\n\n> Summary\n", (new LlmsTxt())->index('Site', 'Summary', [], []));
    }

    public function test_full_puts_each_page_under_its_title_with_headings_shifted(): void
    {
        $txt = (new LlmsTxt())->full('Site', 'Summary', [
            ['entry' => new GeoEntry('About', 'https://site.test/about'), 'markdown' => "# About\n\nText\n\n## Team\n\n###### Deep\n\n```\n# not a heading\n```\n"],
            ['entry' => new GeoEntry('Contact', 'https://site.test/contact'), 'markdown' => "Write to us.\n"],
        ]);

        $this->assertSame(
            "# Site\n\n> Summary\n\n"
            . "## About\n\nSource: https://site.test/about\n\n"
            . "## About\n\nText\n\n### Team\n\n###### Deep\n\n```\n# not a heading\n```\n\n"
            . "## Contact\n\nSource: https://site.test/contact\n\n"
            . "Write to us.\n",
            $txt,
        );
    }

    public function test_entry_markdown_urls(): void
    {
        $this->assertSame('https://site.test/index.md', (new GeoEntry('H', 'https://site.test/'))->markdownUrl());
        $this->assertSame('https://site.test/index.md', (new GeoEntry('H', 'https://site.test'))->markdownUrl());
        $this->assertSame('https://site.test/de/index.md', (new GeoEntry('H', 'https://site.test/de/'))->markdownUrl());
        $this->assertSame('https://site.test/a/b.md', (new GeoEntry('H', 'https://site.test/a/b'))->markdownUrl());
        $this->assertSame('/a.md', (new GeoEntry('H', '/a'))->markdownUrl());
    }

    public function test_entries_sorted_by_section_order_then_title_home_section_first(): void
    {
        $entries = new GeoEntries();
        $entries->source(static fn (string $locale): array => [
            new GeoEntry('Zebra', '/z', section: 'Games'),
            new GeoEntry('Beta', '/b'),
            new GeoEntry('Alpha', '/a', order: 100),
            new GeoEntry('First', '/f', order: 10),
        ]);
        $entries->source(static fn (string $locale): \Generator => yield new GeoEntry('Home ' . $locale, '/', order: 0, section: 'Start'));
        $entries->source(static fn (string $locale): array => [new GeoEntry('Abacus', '/ab', section: 'Games')]);

        $titles = array_map(static fn (GeoEntry $e): string => $e->section . ':' . $e->title, $entries->for('de'));

        $this->assertSame(
            ['Start:Home de', 'Games:Abacus', 'Games:Zebra', 'Pages:First', 'Pages:Alpha', 'Pages:Beta'],
            $titles,
        );
    }

    public function test_filters_run_in_registration_order_on_every_sources_entries(): void
    {
        $entries = new GeoEntries();
        $entries->source(static fn (string $locale): array => [new GeoEntry('Home', '/', order: 0), new GeoEntry('Not found', '/not-found')]);
        $entries->source(static fn (string $locale): array => [new GeoEntry('Tools', '/tools')]);
        $seen = [];
        $entries->filter(static function (array $list, string $locale) use (&$seen): array {
            $seen[] = $locale . ':' . count($list);

            return array_filter($list, static fn (GeoEntry $e): bool => $e->url !== '/not-found');
        });
        $entries->filter(static function (array $list) use (&$seen): array {
            $seen[] = 'second:' . count($list);

            return array_map(static fn (GeoEntry $e): GeoEntry => $e->url === '/tools'
                ? new GeoEntry($e->title, $e->url, 'Handy tools', 'Tools', 5, false)
                : $e, $list);
        });

        $list = $entries->for('en');

        $this->assertSame(['en:3', 'second:2'], $seen);
        $this->assertSame(['Home', 'Tools'], array_map(static fn (GeoEntry $e): string => $e->title, $list));
        $this->assertSame('Handy tools', $list[1]->description);
        $this->assertFalse($list[1]->full);
    }

    public function test_site_fields_come_from_the_registered_source(): void
    {
        $entries = new GeoEntries();
        $this->assertSame([], $entries->siteFields('en'));

        $entries->site(static fn (string $locale): array => ['name' => 'Site ' . $locale]);
        $this->assertSame(['name' => 'Site de'], $entries->siteFields('de'));
    }

    public function test_a_source_yielding_something_else_fails(): void
    {
        $entries = new GeoEntries();
        $entries->source(static fn (): array => ['not an entry']);

        $this->expectException(\UnexpectedValueException::class);
        $entries->for('en');
    }
}
