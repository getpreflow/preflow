<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Dashboard;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\AggregateQuery;
use Preflow\Data\AggregatingStorage;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Dashboard\Dashboard;
use Preflow\Folio\Dashboard\NeedsYou;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * The dashboard (0.76): the "New …" button, the ink band's stats, "Needs you" (Folio's own cards
 * and #[DashboardItem]), the content by sidebar section with aggregate counts, and the cache line.
 */
final class DashboardTest extends TestCase
{
    use PublishingScaffold;

    private \Closure $autoload;
    private ?Application $session = null;
    /** @var array<string, array{disabled_parts: list<string>}> */
    private array $enabled = [];

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->writeModel('contact', ['label' => 'Contacts', 'fields' => ['name' => ['type' => 'string']]]);
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ['email' => 'editor@site.test', 'password_hash' => $hash, 'roles' => ['editor']],
            ]]],
            'session' => ['cookie' => 'folio_dash_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'admin' => ['*'],
            // Notes and posts to look at; no publish, create, redirects, assets, cache or contact.view.
            'editor' => ['folio.dashboard', 'note.view', 'post.view'],
        ]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

        $root = $this->dir . '/app/Folio/';
        $this->autoload = static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\Folio\\')) {
                $file = $root . str_replace('\\', '/', substr($class, strlen('App\\Folio\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoload);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_changes_not_live_and_not_found_cards_by_permission_and_count(): void
    {
        $this->openWork();
        $this->as('admin');
        // The page loads the section after itself, above the content.
        $page = $this->dashboard();
        $this->assertStringContainsString('<div class="folio-dash-later" hx-get="/folio?part=needs" hx-trigger="load" hx-swap="outerHTML"></div>', $page);
        $this->assertLessThan(strpos($page, 'folio-dash-content'), strpos($page, '?part=needs'), 'Needs you comes before the content');
        $this->assertStringNotContainsString('folio-needs-card', $page, 'the comparing happens in the part, not the page');

        $body = $this->part('needs');
        $this->assertStringStartsWith('<section class="folio-dash-section folio-dash-needs"', ltrim($body), 'the section alone');
        $this->assertStringContainsString('<h2 id="folio-needs-title" class="folio-dash-title">Needs you</h2>', $body);
        $this->assertStringContainsString('2 open', $body);
        $this->assertMatchesRegularExpression('#<a class="folio-needs-card" data-tone="warning" href="/folio/post\?status=changed">.*?<span class="folio-needs-title">Posts: 1 change is not live</span>#s', $body);
        $this->assertMatchesRegularExpression('#<a class="folio-needs-card" data-tone="info" href="/folio/redirects\?tab=not-found">.*?<span class="folio-needs-title">1 not-found address to check</span>#s', $body);
    }

    #[RunInSeparateProcess]
    public function test_changes_not_live_and_not_found_cards_need_publish_and_redirects_manage(): void
    {
        $this->openWork();
        // post.view without post.publish, no redirects.manage: neither card, so no section.
        $this->as('editor');
        $this->assertSame('', $this->part('needs'));
    }

    /** A published post with a change that is not live, and a not-found address hit twice (one app, before signing in). */
    private function openWork(): void
    {
        $c = $this->app()->container();
        $dm = $c->get(DataManager::class);
        $post = static fn (array $data): \Preflow\Data\DynamicRecord => \Preflow\Data\DynamicRecord::fromArray($dm->getTypeRegistry()->get('post'), ['uuid' => 'p1'] + $data);
        $dm->saveType($working = $post(['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]), false);
        $published = $c->get(\Preflow\Publishing\Publisher::class)->publish($working, 'test');
        $dm->saveType($post(['title' => ['en' => 'Hello again'], 'slug' => ['en' => 'hello'], '_version' => $published->get('_version')]), false);
        $notFound = $c->get(\Preflow\Folio\Redirects\NotFoundEntries::class);
        $notFound->record('/old-page', 'en', new \DateTimeImmutable('-10 minutes'));
        $notFound->record('/old-page', 'en', new \DateTimeImmutable('-5 minutes'));
        self::endSession();
    }

    /** Seeding boots apps of their own; their session must not linger into the signed-in one. */
    private static function endSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    #[RunInSeparateProcess]
    public function test_an_extension_item_renders_and_broken_ones_are_skipped(): void
    {
        $marker = $this->dir . '/item-called';
        $this->item('crm', 'ContactAdmin', 'contact', 'file_put_contents(' . var_export($marker, true) . ', "x"); return new \\Preflow\\Folio\\Dashboard\\NeedsYou("3 contacts to call", "They asked for a call back.", "contact?f=open", "info", "ui-users");');
        $this->item('loud', 'ContactLoud', 'contact', 'return new \\Preflow\\Folio\\Dashboard\\NeedsYou("Loud", url: "contact", tone: "loud");');
        $this->item('oops', 'ContactOops', 'contact', 'throw new \\RuntimeException("db down");');
        $this->item('away', 'ContactAway', 'contact', 'return new \\Preflow\\Folio\\Dashboard\\NeedsYou("Elsewhere", url: "https://other.test/x");');
        $this->item('memo', 'NoteMemo', 'note', 'return null;', notice: 'return \'<p class="memo-notice">Read me</p>\';');
        $this->as('admin');

        $page = $this->dashboard();
        $body = $this->part('needs');
        $this->assertMatchesRegularExpression('#<a class="folio-needs-card" data-tone="info" href="/folio/contact\?f=open">\s*<span class="folio-needs-icon"><svg class="folio-icon" aria-hidden="true"><use href="[^"]*\#ui-users"></use></svg></span>\s*<span class="folio-needs-body"><span class="folio-needs-title">3 contacts to call</span><span class="folio-needs-text">They asked for a call back.</span></span>#', $body);
        $this->assertStringContainsString('1 open', $body, 'the unknown tone, the throw and the other host add no card');
        $this->assertStringNotContainsString('Loud', $body);
        $this->assertStringNotContainsString('other.test', $body);
        $this->assertFileExists($marker);
        // #[DashboardNotice] HTML stays above the section; a null item adds no card.
        $this->assertStringContainsString('<p class="memo-notice">Read me</p>', $page);
        $this->assertLessThan(strpos($page, '?part=needs'), strpos($page, '<p class="memo-notice">Read me</p>'));
        $this->assertStringNotContainsString('memo-notice', $body, 'notices are the page\'s, not the part\'s');
    }

    #[RunInSeparateProcess]
    public function test_an_extension_item_is_only_asked_for_who_may_view_the_type(): void
    {
        $marker = $this->dir . '/item-called';
        $this->item('crm', 'ContactAdmin', 'contact', 'file_put_contents(' . var_export($marker, true) . ', "x"); return new \\Preflow\\Folio\\Dashboard\\NeedsYou("3 contacts to call", url: "contact");');
        $this->as('editor'); // no contact.view

        $this->assertStringNotContainsString('3 contacts to call', $this->part('needs'));
        $this->assertFileDoesNotExist($marker);
    }

    #[RunInSeparateProcess]
    public function test_an_item_without_the_needs_you_return_type_is_a_boot_error(): void
    {
        $this->item('crm', 'ContactAdmin', 'contact', 'return null;', 'string');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('#[DashboardItem] on App\\Folio\\Crm\\Types\\ContactAdmin::item: the method must declare the return type ?Preflow\\Folio\\Dashboard\\NeedsYou.');
        $this->app();
    }

    public function test_an_unknown_tone_is_refused(): void
    {
        $this->assertSame('ui-info', (new NeedsYou('x', url: 'a', tone: 'info'))->glyph());
        $this->assertSame('ui-warn', (new NeedsYou('x', url: 'a'))->glyph());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('NeedsYou tone must be "warning", "info" or "danger", not "loud".');
        new NeedsYou('x', url: 'a', tone: 'loud');
    }

    #[RunInSeparateProcess]
    public function test_content_by_section_with_counts_and_quick_create(): void
    {
        $dm = $this->app()->container()->get(DataManager::class);
        foreach (['n1' => 'One', 'n2' => 'Two'] as $id => $name) {
            $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($dm->getTypeRegistry()->get('note'), ['uuid' => $id, 'name' => $name]), false);
        }
        self::endSession();
        $this->as('admin');

        $this->assertSame('', $this->part('needs'), 'nothing open: no "Needs you" section');
        $body = $this->dashboard();
        // Several sections (content and the library): a card each, with its heading.
        $this->assertMatchesRegularExpression('#<h3 class="folio-dash-group-title">Content</h3>#', $body);
        $this->assertMatchesRegularExpression('#<h3 class="folio-dash-group-title">Library</h3>#', $body);
        $this->assertMatchesRegularExpression('#<a class="folio-dash-row-link" href="/folio/note"><span class="folio-dash-row-label">Notes</span><span class="folio-dash-row-count">2 records</span></a>\s*<a class="btn btn-secondary btn-icon btn-sm folio-dash-add" href="/folio/note/new" aria-label="New note" title="New note">#', $body);
        $this->assertMatchesRegularExpression('#href="/folio/site"><span class="folio-dash-row-label">Site</span></a>\s*</li>#', $body, 'a singleton: no count, no "+"');
        $this->assertStringContainsString('<span class="folio-dash-row-label">Assets</span><span class="folio-dash-row-count">0 files</span>', $body);
        // Each row's type tile carries its family (admin.css tints it).
        $this->assertMatchesRegularExpression('#<li class="folio-dash-row">\s*<span class="folio-type-icon" data-family="page" aria-hidden="true"><svg class="folio-icon"><use href="[^"]*\#ui-file"></use></svg></span>\s*<a class="folio-dash-row-link" href="/folio/post">#', $body);
        $this->assertMatchesRegularExpression('#<span class="folio-type-icon" data-family="asset" aria-hidden="true"><svg class="folio-icon"><use href="[^"]*\#ui-library"></use></svg></span>\s*<a class="folio-dash-row-link" href="/folio/assets">#', $body);
        // The header: View site and "New …" for the first page-family type (posts have a frontend).
        $this->assertMatchesRegularExpression('#<a class="btn btn-secondary" href="/" target="_blank" rel="noopener">.*?View site<span class="folio-sr"> \(opens in a new tab\)</span></a>#s', $body);
        $this->assertMatchesRegularExpression('#<a class="btn btn-primary" href="/folio/post/new">.*?New post</a>#s', $body);
        // The ink band: the site, the two largest families by their largest type, the assets.
        $this->assertMatchesRegularExpression('#<p class="folio-ink-line">Pub · 2 notes · 0 posts · 0 assets<span class="folio-caret" aria-hidden="true"></span></p>#', $body);
        $this->assertStringContainsString('Every word in its place.', $body);
        $this->assertStringContainsString('What needs you, what changed, and where to start.', $body);
    }

    #[RunInSeparateProcess]
    public function test_a_single_section_has_one_card_without_a_heading(): void
    {
        $this->as('editor'); // notes and posts (both Content), no assets.view: one section

        $body = $this->dashboard();
        $this->assertStringContainsString('<span class="folio-dash-row-label">Notes</span>', $body);
        $this->assertSame(1, substr_count($body, '<div class="folio-dash-group">'));
        $this->assertStringNotContainsString('folio-dash-group-title', $body);
        // No create: no "+" and no "New …" in the header.
        $this->assertStringNotContainsString('folio-dash-add', $body);
        $this->assertStringNotContainsString('href="/folio/post/new"', $body);
        $this->assertStringNotContainsString('Contacts', $body, 'a type the user may not view');
    }

    #[RunInSeparateProcess]
    public function test_the_cache_line_needs_the_cache_screens_permission(): void
    {
        $this->as('admin');
        $this->send('GET', '/folio/note'); // compiles templates: the caches hold something
        $body = $this->dashboard();
        $this->assertStringContainsString('<p class="folio-dash-cache" hx-get="/folio?part=cache" hx-trigger="load" hx-swap="outerHTML"></p>', $body, 'loaded after the page');
        $this->assertStringNotContainsString('Caches hold', $body);
        $this->assertStringContainsString('<p class="folio-dash-tip">Press ? for shortcuts</p>', $body);
        $body = $this->part('cache');
        $this->assertMatchesRegularExpression('#<p class="folio-dash-cache">.*?<span>Caches hold [0-9.]+ [KMG]?B, mostly [^<]+\.</span> <a href="/folio/cache">Open cache</a></p>#s', $body);
    }

    #[RunInSeparateProcess]
    public function test_no_cache_line_without_the_cache_screens_permission(): void
    {
        $this->as('editor'); // no folio.cache, which the Cache screen asks
        $body = $this->dashboard();
        $this->assertStringNotContainsString('folio-dash-cache', $body);
        $this->assertStringContainsString('Press ? for shortcuts', $body);
        $this->assertSame('', $this->part('cache'), 'the part asks the permission too');
    }

    public function test_counts_are_aggregates_not_loaded_records(): void
    {
        $base = sys_get_temp_dir() . '/folio_dash_' . bin2hex(random_bytes(4));
        mkdir($base . '/m', 0777, true);
        mkdir($base . '/s', 0777, true);
        file_put_contents($base . '/m/page.json', (string) json_encode(['key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages', 'frontend' => ['route' => '/{slug}'], 'fields' => ['title' => ['type' => 'string']]]));
        file_put_contents($base . '/m/job.json', (string) json_encode(['key' => 'job', 'table' => 'job', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Jobs', 'section' => 'board', 'family' => 'reference', 'fields' => ['title' => ['type' => 'string']]]));
        $registry = new TypeRegistry($base . '/m');
        $spy = new CountingDriver(new JsonFileDriver($base . '/s'));
        $dm = new DataManager(['json' => $spy], 'json', $registry);
        foreach (['a', 'b', 'c'] as $id) {
            $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($registry->get('job'), ['uuid' => $id, 'title' => $id]), false);
        }
        $catalog = new TypeCatalog($base . '/m');
        $spy->finds = 0;

        $view = (new Dashboard($catalog, $dm, new DisplayLabels($catalog, $registry), '/folio'))->view(static fn (string $permission): bool => true);

        $this->assertSame(0, $spy->finds, 'no record is loaded to be counted');
        $this->assertSame(2, $spy->aggregates);
        $this->assertSame(['3 jobs', '0 pages'], $view['stats']);
        $this->assertSame(['content', 'board'], array_column($view['sections'], 'name'), 'sidebar order: content, then the sections types add');
        $this->assertSame('3 records', $view['sections'][1]['rows'][0]['meta']);
        $this->assertSame(['label' => 'New page', 'url' => '/folio/page/new'], $view['new']);
        $this->assertNull((new Dashboard($catalog, $dm, new DisplayLabels($catalog, $registry), '/folio'))->view(static fn (string $p): bool => $p !== 'page.create')['new'], 'no page type to create: no button');

        foreach ([$base . '/s/job', $base . '/s', $base . '/m'] as $dir) {
            array_map('unlink', glob($dir . '/*.*') ?: []);
        }
        @rmdir($base . '/s/job');
        @rmdir($base . '/s');
        @rmdir($base . '/m');
        @rmdir($base);
    }

    public function test_the_layout_keeps_to_the_width_at_375(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../assets/admin.css');
        // Grids of cards drop to one column on a phone, never wider than the content.
        $this->assertMatchesRegularExpression('/\.folio-needs \{[^}]*grid-template-columns: repeat\(auto-fit, minmax\(min\(320px, 100%\), 1fr\)\);/', $css);
        $this->assertMatchesRegularExpression('/\.folio-dash-groups \{[^}]*grid-template-columns: repeat\(auto-fit, minmax\(min\(300px, 100%\), 1fr\)\);/', $css);
        // Long words (a site name, a type label, a title) wrap instead of pushing the page wide.
        $this->assertMatchesRegularExpression('/\.folio-dash-band \.folio-ink-line \{[^}]*overflow-wrap: anywhere;/', $css);
        $this->assertMatchesRegularExpression('/\.folio-needs-title \{[^}]*overflow-wrap: anywhere;/', $css);
        $this->assertMatchesRegularExpression('/\.folio-dash-row-label \{[^}]*overflow-wrap: anywhere;/', $css);
        $this->assertMatchesRegularExpression('/\.folio-dash-group \{[^}]*min-width: 0;/', $css);
        $this->assertMatchesRegularExpression('/\.folio-dash-row-link \{[^}]*min-width: 0;/', $css);
        $this->assertMatchesRegularExpression('/\.folio-dash-foot \{[^}]*flex-wrap: wrap;/', $css);
        $this->assertMatchesRegularExpression('/\.folio-needs-card\[data-tone="warning"\] \{ --tone: var\(--c-warning\);/', $css, 'admin.css alone maps a tone to its colours');
    }

    /** An extension $name customizing $type with one #[DashboardItem] method whose body is $body. */
    private function item(string $name, string $class, string $type, string $body, string $returns = '?\\Preflow\\Folio\\Dashboard\\NeedsYou', ?string $notice = null): void
    {
        $noticeMethod = $notice === null ? '' : "    #[\\Preflow\\Folio\\Extension\\DashboardNotice]\n    public function notice(): ?string { {$notice} }\n";
        $ns = 'App\\Folio\\' . ucfirst($name);
        $this->write('app/Folio/' . ucfirst($name) . '/Extension.php', "<?php\nnamespace {$ns};\n#[\\Preflow\\Folio\\Extension\\Attributes\\FolioExtension(name: '{$name}', label: '{$name}')]\nfinal class Extension extends \\Preflow\\Folio\\Extension\\Extension {}\n");
        $this->write('app/Folio/' . ucfirst($name) . "/Types/{$class}.php", "<?php\nnamespace {$ns}\\Types;\n#[\\Preflow\\Folio\\Extension\\Attributes\\FolioType('{$type}')]\nfinal class {$class}\n{\n    #[\\Preflow\\Folio\\Extension\\DashboardItem]\n    public function item(): {$returns} { {$body} }\n{$noticeMethod}}\n");
        $this->enabled[$name] = ['disabled_parts' => []];
        $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => $this->enabled]));
    }

    private function as(string $role): void
    {
        $this->session = $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $m[1] ?? '', 'email' => "{$role}@site.test", 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    private function dashboard(): string
    {
        $response = $this->send('GET', '/folio');
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /** A part the dashboard loads after itself (htmx's GET {prefix}?part=…). */
    private function part(string $name): string
    {
        $response = $this->send('GET', '/folio?part=' . $name, ['HX-Request' => 'true']);
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /** @param array<string, string> $headers */
    private function send(string $method, string $uri, array $headers = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->session->handle($request->withQueryParams($query));
    }
}

/** A storage that counts how often records are loaded (findMany) and how often counted (aggregate). */
final class CountingDriver implements AggregatingStorage
{
    public int $finds = 0;
    public int $aggregates = 0;

    public function __construct(private readonly JsonFileDriver $inner) {}

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
    {
        return $this->inner->findOne($type, $id, $idField);
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        $this->finds++;

        return $this->inner->findMany($type, $query);
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        $this->inner->save($type, $id, $data, $idField);
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        $this->inner->delete($type, $id, $idField);
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        return $this->inner->exists($type, $id, $idField);
    }

    public function lastInsertId(): string|int
    {
        return $this->inner->lastInsertId();
    }

    public function rawQuery(string $sql, array $bindings = []): array
    {
        return $this->inner->rawQuery($sql, $bindings);
    }

    public function aggregate(AggregateQuery $query): array
    {
        $this->aggregates++;

        return $this->inner->aggregate($query);
    }
}
