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
        // Edited as an editor saves: a new working version (the status counts compare versions).
        $this->publisher()->saveDraft(\Preflow\Data\DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('post'), ['uuid' => $id, 'title' => ['en' => $title . ' (edited)'], 'slug' => ['en' => strtolower($title)]]), null);
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

    /** Saves a record through the data layer on a clock at $at, so its change date is $at. */
    private function savedAt(string $type, string $id, array $data, \DateTimeImmutable $at): void
    {
        $dm = $this->dm();
        $record = \Preflow\Data\DynamicRecord::fromArray($dm->getTypeRegistry()->get($type), ['uuid' => $id] + $data);
        \Preflow\Core\Context\Contexts::run(
            \Preflow\Testing\ContextFactory::forTest()->withClock(new \Preflow\Core\Context\FrozenClock($at)),
            static fn () => $dm->saveType($record, false),
        );
    }

    public function test_every_list_ends_in_a_changed_column_from_the_stored_change_date(): void
    {
        $this->writeModel('game', ['label' => 'Games', 'fields' => ['title' => ['type' => 'string']]]);
        $at = new \DateTimeImmutable('-3 hours');
        $this->savedAt('game', 'g1', ['title' => 'Chess'], $at);
        $this->working('game', 'g2', ['title' => 'Go']); // written past the data layer: no date

        $html = $this->html('/folio/game');

        $this->assertMatchesRegularExpression('#</a></th><th><a class="folio-sort" href="/folio/game\?sort=-_updated_at">Changed <span class="folio-sort-mark is-idle" aria-hidden="true"></span></a></th>#', $html);
        $iso = $at->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
        $this->assertMatchesRegularExpression('#<td class="folio-cell-changed"><time datetime="' . preg_quote($iso, '#') . '" title="[^"]+">3 hours ago</time></td>#', $html, 'ISO 8601 for machines');
        $this->assertSame(2, substr_count($html, 'folio-cell-changed'), 'one cell per row; a record without a date shows a dash');
        $this->assertStringContainsString('<span class="folio-muted" aria-hidden="true">–</span>', $html);
    }

    public function test_the_changed_column_sorts_by_the_change_date(): void
    {
        $this->writeModel('game', ['label' => 'Games', 'fields' => ['title' => ['type' => 'string']]]);
        $this->savedAt('game', 'g1', ['title' => 'Middle'], new \DateTimeImmutable('-2 days'));
        $this->savedAt('game', 'g2', ['title' => 'Newest'], new \DateTimeImmutable('-1 hour'));
        $this->savedAt('game', 'g3', ['title' => 'Oldest'], new \DateTimeImmutable('-30 days'));

        $titles = static function (string $html): array {
            preg_match_all('#<a class="folio-row-link" href="/folio/game/g\d/edit">([^<]+)</a>#', $html, $m);

            return $m[1];
        };

        $newest = $this->html('/folio/game?sort=-_updated_at');
        $this->assertSame(['Newest', 'Middle', 'Oldest'], $titles($newest));
        $this->assertStringContainsString('<th aria-sort="descending"><a class="folio-sort" href="/folio/game?sort=_updated_at">Changed <span class="folio-sort-mark" aria-hidden="true">↓</span></a></th>', $newest);

        $oldest = $this->html('/folio/game?sort=_updated_at');
        $this->assertSame(['Oldest', 'Middle', 'Newest'], $titles($oldest));
        $this->assertStringContainsString('<th aria-sort="ascending"><a class="folio-sort" href="/folio/game">Changed', $oldest, 'a third click: the list\'s own order');
    }

    public function test_a_folio_save_leaves_a_types_own_updated_field_and_moves_the_change_date(): void
    {
        $this->writeModel('memo', ['label' => 'Memos', 'fields' => ['title' => ['type' => 'string'], 'updated_at' => ['type' => 'string']]]);
        $this->savedAt('memo', 'm1', ['title' => 'First', 'updated_at' => '2020-01-02 03:04:05'], new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        $res = $this->post('/folio/memo/m1', ['title' => 'Second', 'updated_at' => '2020-01-02 03:04:05', '_locale' => 'en']);

        $this->assertSame(302, $res->getStatusCode());
        $stored = $this->dm()->driver('json')->findOne('memo', 'm1') ?? [];
        $this->assertSame('Second', $stored['title'] ?? null);
        $this->assertSame('2020-01-02 03:04:05', $stored['updated_at'] ?? null, 'the site\'s own field is its content');
        $this->assertNotSame('2026-01-01T00:00:00+00:00', $stored[\Preflow\Data\ChangeDates::FIELD] ?? null, 'the change date moved');
        $this->assertNotNull(\Preflow\Data\ChangeDates::parse($stored[\Preflow\Data\ChangeDates::FIELD] ?? null));
    }

    public function test_the_changed_column_reads_the_change_date_not_a_types_own_updated_field(): void
    {
        $this->writeModel('memo', ['label' => 'Memos', 'fields' => ['title' => ['type' => 'string'], 'updated_at' => ['type' => 'string']]]);
        $this->savedAt('memo', 'm1', ['title' => 'First', 'updated_at' => '2020-01-02 03:04:05'], new \DateTimeImmutable('-3 hours'));

        $html = $this->html('/folio/memo');

        $this->assertMatchesRegularExpression('#<td class="folio-cell-changed"><time datetime="[^"]+" title="[^"]+">3 hours ago</time></td>#', $html);
        $this->assertStringContainsString('2020-01-02 03:04:05', $html, 'the site\'s own field: an ordinary column');
    }

    public function test_a_type_hides_the_changed_column_with_list_changed_false(): void
    {
        $this->writeModel('toy', ['label' => 'Toys', 'fields' => ['title' => ['type' => 'string']]]);
        $this->writeModel('game', ['label' => 'Games', 'list' => ['changed' => false], 'fields' => ['title' => ['type' => 'string']]]);
        $this->working('game', 'g1', ['title' => 'Chess']);

        $this->working('toy', 't1', ['title' => 'Ball']);
        $this->assertMatchesRegularExpression('#<th[^>]*>(?:<a [^>]*>)?Changed\\b#', $this->html('/folio/toy'), 'a type that does not hide it has the column');

        $html = $this->html('/folio/game');

        $this->assertDoesNotMatchRegularExpression('#<th[^>]*>(?:<a [^>]*>)?Changed\\b#', $html);
        $this->assertStringNotContainsString('folio-cell-changed', $html);
    }

    public function test_a_type_may_list_its_own_updated_field_beside_the_changed_column(): void
    {
        $this->writeModel('memo', ['label' => 'Memos', 'list' => ['columns' => ['title', 'updated_at']], 'fields' => ['title' => ['type' => 'string'], 'updated_at' => ['type' => 'string']]]);
        $this->savedAt('memo', 'm1', ['title' => 'First', 'updated_at' => '2026-01-02 03:04:05'], new \DateTimeImmutable('-3 hours'));

        $html = $this->html('/folio/memo');

        $this->assertSame(1, preg_match_all('#<th[^>]*>(?:<a [^>]*>)?Changed\\b#', $html), 'one Changed column');
        $this->assertStringContainsString('2026-01-02 03:04:05', $html, 'its own field as written');
        $this->assertSame(1, substr_count($html, 'folio-cell-changed'));
    }
}
