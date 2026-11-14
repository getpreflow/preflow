<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Authenticatable;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * The row "…" menu of a list (_list_results.twig) that replaced the Edit button and the trash:
 * Edit, View on site, Move up / down, Unpublish, a divider, Duplicate, Copy preview link, the type's
 * header record actions and Delete…, each only for those who may and in the states it makes sense.
 * The "post" type publishes at "/{slug}" and is orderable; "note" publishes without a frontend.
 */
final class ListRowMenuTest extends TestCase
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

    /** The menu of one row: from its details to the end of its panel. */
    private function menu(string $html, string $id): string
    {
        $at = strpos($html, 'href="/folio/post/' . $id . '/edit"');
        $this->assertNotFalse($at, "row {$id}");
        $start = strpos($html, '<details class="folio-row-menu"', $at);
        $this->assertNotFalse($start, "menu of {$id}");

        return substr($html, $start, strpos($html, '</details>', $start) - $start);
    }

    private function list(string $uri = '/folio/post'): string
    {
        $res = $this->get($uri);
        $this->assertSame(200, $res->getStatusCode());

        return (string) $res->getBody();
    }

    private function publishPost(string $id, string $slug): void
    {
        $this->publisher()->publish($this->working('post', $id, ['title' => ['en' => ucfirst($slug)], 'slug' => ['en' => $slug]]), null);
    }

    /** @return list<string> the items' labels in order, "—" for the divider */
    private static function items(string $menu): array
    {
        preg_match_all('#<div class="folio-menu-sep" role="separator"></div>|role="menuitem"[^>]*>(?:<svg.*?</svg>)?<span>([^<]*)</span>#s', $menu, $m, PREG_SET_ORDER);

        return array_map(static fn (array $hit): string => isset($hit[1]) ? html_entity_decode($hit[1]) : '—', $m);
    }

    /** A signed-in user who may do everything but $denied. */
    private function signInWithout(string ...$denied): void
    {
        $container = $this->app()->container();
        $user = new class implements Authenticatable {
            public function getAuthId(): string { return 'u1'; }
            public function getPasswordHash(): string { return ''; }
            public function getRoles(): array { return ['editor']; }
            public function hasRole(string $role): bool { return $role === 'editor'; }
        };
        $auth = new AuthManager([], 'web');
        $auth->setUser($user, 'web');
        $access = new Access($auth, $container->get(Access::class)->registry(), false, ['web' => 'staff']);
        $access->setResolver(new class($denied) implements PermissionResolver {
            /** @param list<string> $denied */
            public function __construct(private readonly array $denied) {}

            public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
            {
                return !in_array($permission, $this->denied, true);
            }
        });
        $container->instance(Access::class, $access);
    }

    public function test_items_for_a_published_record_and_someone_who_may_do_everything(): void
    {
        $this->publishPost('p1', 'hello');
        $this->publishPost('p2', 'world');

        $menu = $this->menu($this->list(), 'p2');

        // The board's order: the record's own actions, a divider, then the danger items.
        $this->assertSame(['Edit', 'View on site', 'Copy preview link', 'Duplicate', 'Move up', 'Move down', '—', 'Unpublish', 'Delete…'], self::items($menu));
        $this->assertStringContainsString('<button type="submit" class="folio-menu-item is-danger" role="menuitem"><svg class="folio-icon" aria-hidden="true"><use href="/folio/_assets/icons.svg', substr($menu, (int) strpos($menu, '/unpublish"')));
        $this->assertStringContainsString('<summary class="btn btn-ghost btn-icon btn-sm" aria-label="More actions for World" title="More actions for World" aria-haspopup="menu"><svg class="folio-icon" aria-hidden="true"><use href="/folio/_assets/icons.svg', $menu);
        $this->assertStringContainsString('<div class="folio-menu folio-row-menu-panel" role="menu" aria-label="More actions for World">', $menu);
        $this->assertStringContainsString('<a class="folio-menu-item" role="menuitem" href="/folio/post/p2/edit" aria-keyshortcuts="E" data-folio-menu-edit>', $menu);
        $this->assertStringContainsString('<kbd class="folio-menu-hint" aria-hidden="true">E</kbd>', $menu);
        $this->assertStringContainsString('<a class="folio-menu-item" role="menuitem" href="/world" target="_blank" rel="noopener">', $menu);
        // Unpublish: the editor's route and prompt (its address count), then back to the list.
        $this->assertMatchesRegularExpression('#<form method="post" action="/folio/post/p2/unpublish" data-confirm="[^"]+" data-removal-addresses="1">\s*<input type="hidden" name="_csrf_token" value="[^"]*">\s*<input type="hidden" name="_from" value="list">#', $menu);
        $this->assertStringContainsString('<input type="hidden" name="_query" value="">', $menu, 'the view to come back to');
        $this->assertStringContainsString('<form method="post" action="/folio/post/p2/duplicate">', $menu);
        $this->assertStringContainsString('<kbd class="folio-menu-hint" aria-hidden="true">D</kbd>', $menu);
        $this->assertStringContainsString('data-folio-copy-link="/folio/post/p2/share" hidden>', $menu);
    }

    public function test_items_follow_the_state(): void
    {
        $this->working('post', 'd1', ['title' => ['en' => 'Draft'], 'slug' => ['en' => 'draft']]);
        $this->publishPost('c1', 'live');
        $this->working('post', 'c1', ['title' => ['en' => 'Live'], 'slug' => ['en' => 'renamed']]);

        $html = $this->list();

        $draft = self::items($this->menu($html, 'd1'));
        $this->assertNotContains('View on site', $draft, 'a draft is not on the site');
        $this->assertNotContains('Unpublish', $draft);
        $changed = $this->menu($html, 'c1');
        $this->assertStringContainsString('href="/live" target="_blank"', $changed, 'the live address, not the edited one');
        $this->assertContains('Unpublish', self::items($changed));
    }

    public function test_items_follow_the_permissions(): void
    {
        $this->publishPost('p1', 'hello');
        $this->publishPost('p2', 'world');

        $this->signInWithout('post.publish');
        $this->assertSame(['Edit', 'View on site', 'Copy preview link', 'Duplicate', 'Move up', 'Move down', '—', 'Delete…'], self::items($this->menu($this->list(), 'p2')));

        $this->booted = null;
        $this->signInWithout('post.edit');
        $this->assertSame(['View on site', 'Duplicate', '—', 'Unpublish', 'Delete…'], self::items($this->menu($this->list(), 'p2')), 'no Edit, no moves, no preview link');

        $this->booted = null;
        $this->signInWithout('post.delete', 'post.create');
        $this->assertSame(['Edit', 'View on site', 'Copy preview link', 'Move up', 'Move down', '—', 'Unpublish'], self::items($this->menu($this->list(), 'p2')));

        $this->booted = null;
        $this->signInWithout('post.delete', 'post.publish');
        $this->assertSame(['Edit', 'View on site', 'Copy preview link', 'Duplicate', 'Move up', 'Move down'], self::items($this->menu($this->list(), 'p2')), 'no danger item: no divider');
    }

    public function test_duplicate_and_copy_link_items_by_permission(): void
    {
        $this->publishPost('p1', 'hello');

        $all = $this->menu($this->list(), 'p1');
        $this->assertStringContainsString('action="/folio/post/p1/duplicate"', $all);
        $this->assertStringContainsString('data-folio-copy-link="/folio/post/p1/share"', $all);

        $this->booted = null;
        $this->signInWithout('post.create');
        $noCreate = $this->menu($this->list(), 'p1');
        $this->assertStringNotContainsString('/duplicate"', $noCreate, 'Duplicate needs the create permission');
        $this->assertStringContainsString('data-folio-copy-link="/folio/post/p1/share"', $noCreate);

        $this->booted = null;
        $this->signInWithout('post.edit');
        $noEdit = $this->menu($this->list(), 'p1');
        $this->assertStringContainsString('/duplicate"', $noEdit);
        $this->assertStringNotContainsString('data-folio-copy-link', $noEdit, 'the preview link asks what the editor asks: edit');
    }

    public function test_no_preview_link_without_a_frontend(): void
    {
        $this->working('note', 'n1', ['name' => 'Remember']);

        $note = (string) $this->get('/folio/note')->getBody();

        $this->assertStringNotContainsString('data-folio-copy-link', $note, 'no page to preview');
        $this->assertStringContainsString('action="/folio/note/n1/duplicate"', $note);
    }

    public function test_row_form_actions_encode_the_id(): void
    {
        $this->working('note', 'n 1', ['name' => 'Remember']);

        $note = (string) $this->get('/folio/note')->getBody();

        $this->assertStringContainsString('action="/folio/note/n%201/duplicate"', $note);
        $this->assertStringContainsString('action="/folio/note/n%201/delete"', $note);
    }

    public function test_delete_keeps_delete_plan_and_confirm(): void
    {
        $this->publishPost('p1', 'hello');

        $menu = $this->menu($this->list(), 'p1');

        $this->assertMatchesRegularExpression('#<form method="post" action="/folio/post/p1/delete" data-confirm="Delete this record\?" data-delete-plan="/folio/post/_delete-plan" data-delete-id="p1">\s*<input type="hidden" name="_csrf_token" value="[^"]*">\s*<button type="submit" class="folio-menu-item is-danger" role="menuitem">#', $menu);
        $this->assertSame('Delete…', self::items($menu)[array_key_last(self::items($menu))], 'the danger item comes last');
    }

    public function test_no_edit_button_or_trash_left_in_the_list_template(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/_list_results.twig');

        $this->assertStringNotContainsString('class="btn btn-secondary btn-sm" href="{{ prefix }}/{{ type }}/{{ row.id }}/edit"', $template);
        $this->assertStringNotContainsString('btn-icon" data-tone="danger"', $template);
        $this->assertStringNotContainsString('aria-label="{{ text }}"', $template, 'the move buttons are menu items now');
        $this->assertSame(2, substr_count($template, '#ui-trash'), 'the trash only on the menu\'s Delete… and the bulk bar\'s Delete');
        $this->assertStringContainsString('<details class="folio-row-menu" data-folio-row-menu>', $template);
    }

    public function test_unpublish_from_the_list_goes_back_to_the_list(): void
    {
        $this->publishPost('p1', 'hello');

        $res = $this->post('/folio/post/p1/unpublish', ['_from' => 'list']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/post', $res->getHeaderLine('Location'));

        // The view it was made in comes back, checked as a URL is: nothing raw reaches the Location header.
        $this->publishPost('p1', 'hello');
        $res = $this->post('/folio/post/p1/unpublish', ['_from' => 'list', '_query' => "q=hel&status=published&page=2&evil=1&sort=title\r\nX-Injected: 1"]);
        $this->assertSame('/folio/post?q=hel&status=published&page=2', $res->getHeaderLine('Location'));
        $this->assertSame('/folio/post/p1/edit', $this->post('/folio/post/p1/unpublish')->getHeaderLine('Location'), 'from the editor: back to it');
    }

    public function test_share_answers_json_for_the_copy_link_item(): void
    {
        $this->publishPost('p1', 'hello');

        $res = $this->app()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/folio/post/p1/share')
            ->withHeader('Sec-Fetch-Site', 'same-origin')->withHeader('Accept', 'application/json')->withParsedBody([]));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/json', $res->getHeaderLine('Content-Type'));
        $data = json_decode((string) $res->getBody(), true);
        $this->assertMatchesRegularExpression('#/hello\?folio_preview=[^&]+$#', (string) $data['url']);
        $this->assertNull($data['message']);
        $this->assertGreaterThan(0, $data['days']);
    }

    public function test_share_without_the_edit_permission_makes_no_link(): void
    {
        $this->publishPost('p1', 'hello');
        $this->signInWithout('post.edit');

        $res = $this->app()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', '/folio/post/p1/share')
            ->withHeader('Sec-Fetch-Site', 'same-origin')->withHeader('Accept', 'application/json')->withParsedBody([]));

        $this->assertSame(403, $res->getStatusCode());
        $this->assertStringNotContainsString('folio_preview', (string) $res->getBody());
        $this->assertNull($this->stored('post', 'p1')->get('_share_token'), 'no token was made either');
    }
}
