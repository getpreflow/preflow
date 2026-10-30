<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Routing\RouterInterface;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\Screen\ScreenRegistry;
use Preflow\Folio\Http\FolioPermissionMiddleware;
use Preflow\Routing\RouteEntry;

/**
 * The two roles of the spec's example on a staff site, on every Folio admin route and screen:
 * super_admin ['*'] and content_admin (dashboard, the content types page/team/member, assets,
 * the moderation screen; not staff, roles, settings or extensions). A third account without
 * roles shows that every allowed answer comes past the permission check. Accounts (password
 * "secret"): super@, content@, plain@ (staff records, signed in through the chain).
 *
 * The table is checked against the routes the app registers (FolioRoutes in staff mode plus
 * the screens): an admin route without a row fails the test, and so does a row for a route
 * that no longer exists or a permission rule no route uses.
 */
final class TwoRolesTest extends TestCase
{
    use RbacAppScaffold;

    private const array ACCOUNTS = ['super' => 0, 'content' => 1, 'plain' => 2];

    private const array CONTENT_ADMIN = [
        'folio.dashboard', 'page.*', 'team.*', 'member.*', 'assets.*', 'moderation.approve',
    ];

    private \Closure $autoload;

    protected function setUp(): void
    {
        $this->scaffold();
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
        putenv('RBAC_AI_TEST_KEY');
        $this->removeScaffold();
    }

    /**
     * One row per request: [route "METHOD pattern", uri, body, [super, content, plain]].
     * A {type} route has a row per kind of type: content (page), staff, roles, settings.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: array{int, int, int}}>
     */
    private static function table(): array
    {
        $rows = [
            ['GET /folio', '/folio', [], [200, 200, 403]],
            ['GET /folio/_account', '/folio/_account', [], [200, 200, 200]],
            ['POST /folio/_account/password', '/folio/_account/password', ['current' => 'wrong', 'password' => 'a new password', 'repeat' => 'a new password'], [422, 422, 422]],
            ['POST /folio/_locale', '/folio/_locale', ['locale' => 'en'], [302, 302, 302]], // signed in, no permission
            ['GET /folio/_blocks/{key}/new', '/folio/_blocks/nothing/new?name=body', [], [404, 404, 403]],
            ['POST /folio/_blocks/duplicate', '/folio/_blocks/duplicate', [], [400, 400, 403]],
            ['GET /folio/assets', '/folio/assets', [], [200, 200, 403]],
            ['POST /folio/assets', '/folio/assets', [], [302, 302, 403]], // no file: back to the library with a flash
            ['GET /folio/assets/{id}', '/folio/assets/missing', [], [404, 404, 403]],
            ['POST /folio/assets/{id}', '/folio/assets/missing', [], [404, 404, 403]],
            ['POST /folio/assets/{id}/delete', '/folio/assets/missing/delete', [], [404, 404, 403]],
            ['POST /folio/assets/{id}/replace', '/folio/assets/missing/replace', [], [404, 404, 403]],
            ['GET /folio/assets/{id}/edit', '/folio/assets/missing/edit', [], [404, 404, 403]],
            ['POST /folio/assets/{id}/edit', '/folio/assets/missing/edit', ['op' => 'rotate-right'], [404, 404, 403]],
            ['POST /folio/assets/{id}/versions/{n}/restore', '/folio/assets/missing/versions/1/restore', [], [404, 404, 403]],
            ['POST /folio/assets/{id}/versions/{n}/delete', '/folio/assets/missing/versions/1/delete', [], [404, 404, 403]],
            ['GET /folio/assets/bin', '/folio/assets/bin', [], [200, 200, 403]],
            ['POST /folio/assets/bin/{id}/restore', '/folio/assets/bin/missing/restore', [], [404, 404, 403]],
            ['POST /folio/assets/{id}/copy', '/folio/assets/missing/copy', [], [404, 404, 403]],
            ['POST /folio/assets/{id}/remake', '/folio/assets/missing/remake', [], [404, 404, 403]],
            ['GET /folio/extensions', '/folio/extensions', [], [200, 403, 403]],
            ['POST /folio/extensions/{name}', '/folio/extensions/nothing', [], [404, 403, 403]],
            ['GET /folio/cache', '/folio/cache', [], [200, 403, 403]],
            ['POST /folio/cache/clear', '/folio/cache/clear', ['key' => 'nothing'], [404, 403, 403]],
            ['POST /folio/cache/opcache-reset', '/folio/cache/opcache-reset', [], [302, 403, 403]],
            ['GET /folio/ai', '/folio/ai', [], [200, 403, 403]],
            ['POST /folio/ai/test', '/folio/ai/test', [], [200, 403, 403]], // the unknown provider fails without a request; the page shows why
            ['GET /folio/ai/tuning', '/folio/ai/tuning', [], [200, 403, 403]],
            ['POST /folio/ai/tuning', '/folio/ai/tuning', ['levels' => ['project' => '']], [302, 403, 403]], // nothing stored
            ['GET /folio/ai/glossary', '/folio/ai/glossary', [], [200, 403, 403]],
            ['POST /folio/ai/glossary', '/folio/ai/glossary', ['action' => 'save', 'rows' => []], [302, 403, 403]],
            ['GET /folio/translations', '/folio/translations', [], [200, 403, 403]],
            ['POST /folio/translations', '/folio/translations', ['locale' => 'en', 'key' => 'folio.common.save', 'text' => ''], [302, 403, 403]], // an empty text: nothing stored
            ['POST /folio/translations/reset', '/folio/translations/reset', ['locale' => 'en', 'key' => 'folio.common.save'], [302, 403, 403]],
            ['POST /folio/translations/save-all', '/folio/translations/save-all', ['locale' => 'en', 'keys' => ['folio.common.save'], 'texts' => ['Save']], [302, 403, 403]],
            // Past the permission check (and ai.translate, which content_admin lacks): a group this site does not have (422), an asset that does not exist (404).
            ['POST /folio/translations/_ai/suggest', '/folio/translations/_ai/suggest', ['group' => 'nothing', 'locale' => 'de', 'scope' => 'needed'], [422, 403, 403]],
            ['POST /folio/assets/{id}/_ai/suggest', '/folio/assets/missing/_ai/suggest', ['locale' => 'de'], [404, 403, 403]],
            ['GET /folio/queue', '/folio/queue', [], [200, 200, 403]],
            ['POST /folio/queue/{id}/approve', '/folio/queue/m1/approve', [], [302, 302, 403]],
        ];

        // [type, record id, a valid new record, a valid update, the record to delete, answers that differ for this type].
        // Allowed answers that aren't 2xx/3xx come from the controller, past the permission check: a type
        // without a preview page, publishing or ordering answers 404, as do new/edit of a singleton.
        $none = ['POST /folio/{type}/preview' => 404, 'POST /folio/{type}/{id}/preview' => 404];
        $types = [
            'content' => ['page', 'p1', ['title' => 'New page'], ['title' => 'One'], 'p-del', []],
            'staff' => ['staff', 's-plain', ['email' => 'new@site.test', 'name' => 'New', 'roles' => [''], 'active' => '1'],
                ['email' => 'plain@site.test', 'name' => 'Plain', 'roles' => [''], 'active' => '1'], 's-spare', $none],
            'roles' => ['rbac_role', 'r-spare', ['name' => 'helper', 'label' => 'Helper', 'population' => 'staff', 'permissions' => ['page.view']],
                ['name' => 'spare', 'label' => 'Spare', 'population' => 'staff', 'permissions' => ['page.view']], 'r-spare', $none],
            'settings' => ['moderation_settings', 'moderation_settings', ['welcome' => 'Hi'], ['welcome' => 'Hi'], null, $none + [
                'GET /folio/{type}/new' => 404, 'POST /folio/{type}' => 404,
                'GET /folio/{type}/{id}/edit' => 404, 'GET /folio/{type}/{id}/label' => 404,
                'GET /folio/{type}/_export' => 404,
                'POST /folio/{type}/_delete-plan' => 404,
                'POST /folio/{type}/_bulk/delete' => 404, 'POST /folio/{type}/_bulk/{name}' => 404,
            ]],
        ];
        foreach ($types as $kind => [$type, $id, $new, $update, $deletable, $differs]) {
            $content = $kind === 'content';
            $row = static function (string $route, string $uri, array $body, int $status) use (&$rows, $content, $differs): void {
                $status = $differs[$route] ?? $status;
                $rows[] = [$route, $uri, $body, [$status, $content ? $status : 403, 403]];
            };
            $row('GET /folio/{type}', "/folio/{$type}", [], 200);
            $row('GET /folio/{type}/new', "/folio/{$type}/new", [], 200);
            $row('POST /folio/{type}/preview', "/folio/{$type}/preview", $new, 200);
            $row('POST /folio/{type}', "/folio/{$type}", $new, 302);
            $row('GET /folio/{type}/_export', "/folio/{$type}/_export", [], 200);
            $row('GET /folio/{type}/{id}/edit', "/folio/{$type}/{$id}/edit", [], 200);
            $row('GET /folio/{type}/{id}/label', "/folio/{$type}/{$id}/label", [], 200);
            $row('GET /folio/_relation/{type}', "/folio/_relation/{$type}", [], 200);
            $row('POST /folio/{type}/_delete-plan', "/folio/{$type}/_delete-plan", ['ids' => [$id]], 200);
            // Past the permission check: no record with that id (422), no bulk action of that name (404).
            $row('POST /folio/{type}/_bulk/delete', "/folio/{$type}/_bulk/delete", ['ids' => ['missing']], 422);
            $row('POST /folio/{type}/_bulk/{name}', "/folio/{$type}/_bulk/nothing", ['ids' => ['missing']], 404);
            $row('POST /folio/{type}/{id}/preview', "/folio/{$type}/{$id}/preview", $update, 200);
            $row('POST /folio/{type}/{id}/unpublish', "/folio/{$type}/{$id}/unpublish", [], 404);
            $row('POST /folio/{type}/{id}/discard', "/folio/{$type}/{$id}/discard", [], 404);
            $row('POST /folio/{type}/{id}/autosave', "/folio/{$type}/{$id}/autosave", $update, 404);
            // Past the permission check: a language the site does not have (404).
            $row('POST /folio/{type}/{id}/_translations/confirm', "/folio/{$type}/{$id}/_translations/confirm", ['locale' => 'xx'], 404);
            // Past the permission check: this single-language site has nothing to translate (404).
            $row('POST /folio/{type}/{id}/_ai/estimate', "/folio/{$type}/{$id}/_ai/estimate", ['locale' => 'de', 'scope' => 'needed'], 404);
            $row('POST /folio/{type}/{id}/_ai/suggest', "/folio/{$type}/{$id}/_ai/suggest", ['locale' => 'de', 'scope' => 'needed'], 404);
            $row('GET /folio/{type}/_ai/run', "/folio/{$type}/_ai/run", [], 404);
            $row('POST /folio/{type}/_ai/run', "/folio/{$type}/_ai/run", ['step' => 'estimate', 'locales' => ['de'], 'scope' => 'needed'], 404);
            $row('POST /folio/{type}/_ai/jobs/{job}/start', "/folio/{$type}/_ai/jobs/nojob/start", [], 404);
            $row('POST /folio/{type}/_ai/jobs/{job}/step', "/folio/{$type}/_ai/jobs/nojob/step", [], 404);
            $row('POST /folio/{type}/_ai/jobs/{job}/stop', "/folio/{$type}/_ai/jobs/nojob/stop", [], 404);
            $row('POST /folio/{type}/{id}/revisions/{rev}/restore', "/folio/{$type}/{$id}/revisions/r1/restore", [], 404);
            $row('POST /folio/{type}/{id}/share', "/folio/{$type}/{$id}/share", [], 404);
            $row('POST /folio/{type}/{id}/share/revoke', "/folio/{$type}/{$id}/share/revoke", [], 404);
            $row('POST /folio/{type}/{id}', "/folio/{$type}/{$id}", $update, 302);
            $row('POST /folio/{type}/{id}/move', "/folio/{$type}/{$id}/move", [], 404);
            $row('POST /folio/{type}/{id}/_action/{name}', "/folio/{$type}/{$id}/_action/nothing", [], 404);
            if ($deletable !== null) {
                $row('POST /folio/{type}/{id}/delete', "/folio/{$type}/{$deletable}/delete", [], 302);
            }
        }
        // A real record action, then its own permission (staff.edit).
        $rows[] = ['POST /folio/{type}/{id}/_action/{name}', '/folio/staff/s-plain/_action/disable', [], [302, 403, 403]];

        return $rows;
    }

    private function site(): void
    {
        $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => [
            'rbac' => ['disabled_parts' => [], 'enabled_parts' => ['model:staff']],
            'moderation' => ['disabled_parts' => []],
        ]]));
        $this->write('app/Folio/Moderation/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Moderation;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'moderation', label: 'Moderation')]
            final class Extension extends \Preflow\Folio\Extension\Extension
            {
                public function settings(): array
                {
                    return ['welcome' => ['type' => 'string', 'default' => 'Hello']];
                }
            }
            PHP);
        $this->write('app/Folio/Moderation/Screens/Queue.php', <<<'PHP'
            <?php
            namespace App\Folio\Moderation\Screens;
            #[\Preflow\Folio\Extension\Attributes\FolioScreen('queue', 'Queue', permission: 'moderation.approve')]
            final class Queue
            {
                #[\Preflow\Routing\Attributes\Get('/')]
                public function index(): string { return 'queue'; }

                #[\Preflow\Routing\Attributes\Post('/{id}/approve')]
                public function approve(): \Psr\Http\Message\ResponseInterface
                {
                    return new \Nyholm\Psr7\Response(302, ['Location' => '/folio/queue']);
                }
            }
            PHP);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'chain', 'population' => 'staff']],
            'providers' => [
                'staff' => ['class' => \Preflow\Auth\Record\RecordUserProvider::class, 'type' => 'staff', 'active' => 'active'],
                'chain' => ['class' => \Preflow\Auth\ChainUserProvider::class, 'providers' => ['staff']],
            ],
            'session' => ['cookie' => 'rbac_two_roles_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Rbac', 'debug' => 1, 'engine' => 'twig', 'key' => 'rbac-app-test-key', 'url' => 'https://site.test'];\n");
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'folio@site.test']]];\n");
        // AI on (its key from a test env var), so Folio registers the AI routes and the table covers them.
        // The provider is unknown on purpose: the Test button then fails before any request leaves the machine.
        putenv('RBAC_AI_TEST_KEY=test-key');
        $this->write('config/ai.php', "<?php\nreturn ['enabled' => true, 'provider' => 'nonesuch', 'models' => ['nonesuch' => 'none'], 'keys' => ['nonesuch' => 'RBAC_AI_TEST_KEY']];\n");

        $this->install();
        $this->role('r-super', 'staff', 'super_admin', ['*']);
        $this->role('r-content', 'staff', 'content_admin', self::CONTENT_ADMIN);
        $this->role('r-spare', 'staff', 'spare', ['page.view']);
        $this->account('s-super', 'super', ['super_admin']);
        $this->account('s-content', 'content', ['content_admin']);
        $this->account('s-plain', 'plain', []);
        $this->account('s-spare', 'spare', []);
        $dm = $this->container()->get(DataManager::class);
        foreach (['p1' => 'One', 'p-del' => 'Gone'] as $id => $title) {
            $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('page'), ['uuid' => $id, 'title' => $title]), false);
        }
    }

    /** @param list<string> $roles */
    private function account(string $id, string $name, array $roles): void
    {
        $dm = $this->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('staff'), [
            'uuid' => $id, 'email' => "{$name}@site.test", 'name' => ucfirst($name), 'roles' => $roles, 'active' => true,
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT), 'auth_epoch' => '0',
        ]), false);
    }

    /** @return array<string, RouteEntry> "METHOD pattern" => route, for every route behind the permission check */
    private function adminRoutes(): array
    {
        $routes = [];
        foreach ($this->container()->get(RouterInterface::class)->getCollection()->all() as $route) {
            if (in_array(FolioPermissionMiddleware::class, $route->middleware, true)) {
                $routes[$route->method . ' ' . $route->pattern] = $route;
            }
        }

        return $routes;
    }

    /** @return array<string, array{string}> */
    public static function accounts(): array
    {
        return ['super admin' => ['super'], 'content admin' => ['content'], 'no roles' => ['plain']];
    }

    #[RunInSeparateProcess]
    public function test_the_table_covers_every_admin_route_and_rule(): void
    {
        $this->site();
        $routes = $this->adminRoutes();
        $rows = array_unique(array_column(self::table(), 0));

        $this->assertSame([], array_values(array_diff(array_keys($routes), $rows)), 'admin routes without a row in the table');
        $this->assertSame([], array_values(array_diff($rows, array_keys($routes))), 'rows for routes the app does not register');

        // Every handler is a permission rule (incl. SIGNED_IN) or an enabled screen, and every rule is routed.
        $screens = $this->container()->get(ScreenRegistry::class);
        $handlers = [];
        foreach ($routes as $key => $route) {
            $this->assertTrue(
                FolioPermissionMiddleware::rule($route->handler) !== null || $screens->permissionFor(explode('@', $route->handler)[0]) !== null,
                "{$key} ({$route->handler}) has no rule",
            );
            $handlers[] = substr($route->handler, strlen('Preflow\\Folio\\Http\\'));
        }
        $rules = (new \ReflectionClassConstant(FolioPermissionMiddleware::class, 'RULES'))->getValue();
        $this->assertSame([], array_values(array_diff(array_keys($rules), $handlers)), 'permission rules no registered route uses');
        $this->assertSame(['App\\Folio\\Moderation\\Screens\\Queue'], array_column($screens->all(), 'class'));
    }

    #[RunInSeparateProcess]
    #[DataProvider('accounts')]
    public function test_every_admin_route_answers_as_the_role_allows(string $account): void
    {
        $this->site();
        $this->as($account);
        $column = self::ACCOUNTS[$account];

        $wrong = [];
        foreach (self::table() as [$route, $uri, $body, $expected]) {
            $method = explode(' ', $route, 2)[0];
            $status = $this->send($method, $uri, $body)->getStatusCode();
            if ($status !== $expected[$column]) {
                $wrong[] = "{$method} {$uri}: {$status}, expected {$expected[$column]}";
            }
        }

        $this->assertSame([], $wrong);
    }

    #[RunInSeparateProcess]
    #[DataProvider('accounts')]
    public function test_the_sidebar_shows_what_the_role_may_open(string $account): void
    {
        $this->site();
        $this->as($account);

        $expected = [
            'super' => ['/folio', '/folio/page', '/folio/member', '/folio/team', '/folio/assets', '/folio/rbac_role', '/folio/staff', '/folio/moderation_settings', '/folio/queue', '/folio/extensions', '/folio/translations', '/folio/cache', '/folio/ai'],
            'content' => ['/folio', '/folio/page', '/folio/member', '/folio/team', '/folio/assets', '/folio/queue'],
            'plain' => [],
        ][$account];

        $page = (string) $this->send('GET', '/folio/_account')->getBody();
        preg_match('#<nav class="folio-nav"[^>]*>(.*?)</nav>#s', $page, $nav);
        preg_match_all('#<a href="([^"]+)"#', $nav[1] ?? '', $links);
        $shown = $links[1];
        sort($shown);
        sort($expected);

        $this->assertSame($expected, $shown);
    }
}
