<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * The redesigned list screen (boards List and PagesList): the header's type tile and count, the full
 * content width, the status links of a publishing type (All · Published · Changes not live · Drafts,
 * counted over the whole type) and the "status" they narrow to, the home page's marker and the mono
 * address column of a frontend type, mono numbers.
 */
final class ListScreenTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $booted = null;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function app(): Application
    {
        if ($this->booted === null) {
            $this->booted = Application::create($this->dir);
            $this->booted->boot();
        }

        return $this->booted;
    }

    private function html(string $uri): string
    {
        $res = $this->get($uri);
        $this->assertSame(200, $res->getStatusCode(), $uri);

        return (string) $res->getBody();
    }

    private function published(string $id, string $title): void
    {
        $this->publisher()->publish($this->working('post', $id, ['title' => ['en' => $title], 'slug' => ['en' => strtolower($title)]]), null);
    }

    private function changed(string $id, string $title): void
    {
        $this->published($id, $title);
        $this->working('post', $id, ['title' => ['en' => $title . ' (edited)'], 'slug' => ['en' => strtolower($title)]]);
    }

    private function draft(string $id, string $title): void
    {
        $this->working('post', $id, ['title' => ['en' => $title], 'slug' => ['en' => strtolower($title)]]);
    }

    /** Two published posts, one with changes not live, one draft. */
    private function mixedPosts(): void
    {
        $this->published('p1', 'Alpha');
        $this->published('p2', 'Bravo');
        $this->changed('p3', 'Charlie');
        $this->draft('p4', 'Delta');
    }

    /** @return array<string, array{href: string, count: ?string, current: bool}> the status links by label */
    private static function statusLinks(string $html): array
    {
        preg_match('#<div class="folio-segmented folio-status-switch" role="group" aria-label="Status" data-folio-list-status>(.*?)</div>#s', $html, $group);
        preg_match_all('#<a href="([^"]*)"( aria-current="page")?>([^<]+?)(?: <span class="folio-segmented-count[^"]*">([^<]+)</span>)?</a>#', $group[1] ?? '', $m, PREG_SET_ORDER);
        $links = [];
        foreach ($m as $link) {
            $links[trim($link[3])] = ['href' => html_entity_decode($link[1]), 'count' => $link[4] ?? null ?: null, 'current' => $link[2] !== ''];
        }

        return $links;
    }

    public function test_header_shows_the_type_tile_of_its_family_and_the_count(): void
    {
        $this->mixedPosts();
        $html = $this->html('/folio/post');

        // A frontend type defaults to the page family; the tile is the 28px one, before the title.
        $this->assertMatchesRegularExpression('#<div class="folio-title-row"><span class="folio-type-icon folio-type-icon-lg" data-family="page" aria-hidden="true"><svg class="folio-icon"><use href="[^"]*\#ui-file"></use></svg></span><h1>Posts</h1><span class="folio-title-count">4 entries</span></div>#', $html);
        // A type without a family: the neutral tile.
        $this->assertStringContainsString('<span class="folio-type-icon folio-type-icon-lg" data-family="" aria-hidden="true">', $this->html('/folio/note'));
    }

    public function test_the_count_is_the_whole_type_while_the_view_narrows(): void
    {
        $this->mixedPosts();

        $this->assertStringContainsString('<span class="folio-title-count">4 entries</span>', $this->html('/folio/post?q=alpha'));
        $this->assertStringContainsString('<span class="folio-title-count">4 entries</span>', $this->html('/folio/post?status=draft'));
    }

    public function test_lists_use_the_full_content_width(): void
    {
        $this->draft('p1', 'Alpha');

        $this->assertStringContainsString('<main class="folio-content is-wide" id="folio-main" tabindex="-1">', $this->html('/folio/post'));
        $this->assertStringContainsString('<main class="folio-content" id="folio-main" tabindex="-1">', $this->html('/folio/post/p1/edit'), 'editors keep their measure');
    }

    public function test_status_links_count_each_state_over_the_whole_type(): void
    {
        $this->mixedPosts();

        $links = self::statusLinks($this->html('/folio/post?q=alpha'));

        $this->assertSame(['All', 'Published', 'Changes not live', 'Drafts'], array_keys($links));
        $this->assertSame(['4', '2', '1', '1'], array_column($links, 'count'), 'counts over the whole type, not the search');
        $this->assertTrue($links['All']['current']);
        $this->assertSame('/folio/post?q=alpha&status=changed', $links['Changes not live']['href'], 'the link keeps the search');
        $this->assertSame('/folio/post?q=alpha', $links['All']['href']);
    }

    public function test_a_zero_count_hides_the_number(): void
    {
        $this->published('p1', 'Alpha');

        $links = self::statusLinks($this->html('/folio/post'));

        $this->assertSame('1', $links['Published']['count']);
        $this->assertNull($links['Drafts']['count']);
        $this->assertNull($links['Changes not live']['count']);
    }

    public function test_status_changed_narrows_and_pages(): void
    {
        for ($i = 1; $i <= 52; $i++) {
            $this->changed(sprintf('c%02d', $i), sprintf('Changed-%02d', $i));
        }
        $this->published('p1', 'Live-only');
        $this->draft('d1', 'Draft-only');

        $first = $this->html('/folio/post?status=changed');
        $this->assertStringContainsString('1–50 of 52', $first);
        $this->assertStringNotContainsString('Live-only', $first);
        $this->assertStringNotContainsString('Draft-only', $first);
        $this->assertTrue(self::statusLinks($first)['Changes not live']['current']);
        $this->assertStringContainsString('href="/folio/post?status=changed&amp;page=2"', $first, 'the pager keeps the status');

        $second = $this->html('/folio/post?status=changed&page=2');
        $this->assertStringContainsString('51–52 of 52', $second);
        $this->assertStringContainsString('Changed-52 (edited)', $second);
    }

    public function test_status_is_kept_in_sort_links_and_the_search_form(): void
    {
        $this->mixedPosts();

        $html = $this->html('/folio/post?status=published&sort=title');

        $this->assertStringContainsString('<input type="hidden" name="status" value="published">', $html, 'a new search stays in the state');
        $this->assertMatchesRegularExpression('#<a class="folio-sort" href="/folio/post\?status=published&amp;sort=-title">#', $html);
        $this->assertSame('/folio/post?status=draft&sort=title', self::statusLinks($html)['Drafts']['href'], 'a status link keeps the sort');
        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringNotContainsString('Charlie', $html);
        $this->assertStringNotContainsString('Delta', $html);
    }

    public function test_a_type_that_does_not_publish_has_no_status_links_and_ignores_status(): void
    {
        $this->writeModel('game', ['label' => 'Games', 'fields' => ['title' => ['type' => 'string'], 'price' => ['type' => 'number']]]);
        $this->working('game', 'g1', ['title' => 'Chess', 'price' => 12]);

        $html = $this->html('/folio/game?status=draft');

        $this->assertStringNotContainsString('data-folio-list-status', $html);
        $this->assertStringContainsString('Chess', $html);
        $this->assertStringNotContainsString('name="status"', $html);
    }

    public function test_number_columns_are_mono_and_right_aligned(): void
    {
        $this->writeModel('game', ['label' => 'Games', 'fields' => ['title' => ['type' => 'string'], 'price' => ['type' => 'number']]]);
        $this->working('game', 'g1', ['title' => 'Chess', 'price' => 12]);

        $html = $this->html('/folio/game');

        $this->assertMatchesRegularExpression('#<th class="is-number"><a class="folio-sort" href="/folio/game\?sort=price">Price#', $html);
        $this->assertStringContainsString('<td class="is-number">12</td>', $html);
    }

    public function test_home_marker_on_the_home_row_only_and_the_address_in_mono(): void
    {
        $this->writeModel('page', ['label' => 'Pages', 'frontend' => ['route' => '/{slug}', 'home' => 'home'], 'fields' => [
            'title' => ['type' => 'string', 'translatable' => true],
            'slug' => ['type' => 'string', 'translatable' => true],
        ]]);
        $this->writeModel('post', ['label' => 'Posts', 'frontend' => ['route' => '/blog/{slug}'], 'fields' => [
            'title' => ['type' => 'string', 'translatable' => true],
            'slug' => ['type' => 'string', 'translatable' => true],
        ]]);
        $this->write('resources/folio/frontend/page.twig', '<h1>{{ record.title }}</h1>');
        $this->working('page', 'home1', ['title' => ['en' => 'Home'], 'slug' => ['en' => 'home']]);
        $this->working('page', 'about1', ['title' => ['en' => 'About'], 'slug' => ['en' => 'about']]);

        $html = $this->html('/folio/page');

        $this->assertSame(1, substr_count($html, 'class="folio-row-meta"'), 'one home page');
        $this->assertMatchesRegularExpression('#<span class="folio-type-icon" data-family="page" aria-hidden="true"><svg class="folio-icon"><use href="[^"]*\#ui-home"></use></svg></span><span class="folio-row-text"><a class="folio-row-link" href="/folio/page/home1/edit">Home</a><span class="folio-row-meta">The home page · slug “home”</span></span>#', $html);
        $this->assertMatchesRegularExpression('#<use href="[^"]*\#ui-file"></use></svg></span><span class="folio-row-text"><a class="folio-row-link" href="/folio/page/about1/edit">About</a></span>#', $html, 'other rows: their type tile, no meta line');
        $this->assertStringContainsString('<th>Address</th>', $html);
        $this->assertStringContainsString('<td class="folio-cell-address folio-mono">/</td>', $html);
        $this->assertStringContainsString('<td class="folio-cell-address folio-mono">/about</td>', $html);
    }

    public function test_a_type_without_a_frontend_has_no_address_column_and_no_row_tiles(): void
    {
        $this->working('note', 'n1', ['name' => 'Remember']);

        $html = $this->html('/folio/note');

        $this->assertStringNotContainsString('<th>Address</th>', $html);
        $this->assertStringNotContainsString('folio-cell-address', $html);
        $this->assertStringContainsString('<span class="folio-row-text"><a class="folio-row-link" href="/folio/note/n1/edit">Remember</a></span>', $html);
    }
}
