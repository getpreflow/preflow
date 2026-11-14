<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Rbac;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Data\DataManager;
use Preflow\Folio\Permissions\KnownPermissions;
use Psr\Http\Message\ResponseInterface;

/**
 * Permissions an update brought (KnownPermissions::fresh()) are announced to whoever may edit
 * roles: a notice on the Roles list, a badge on its sidebar item, a "Needs you" card on the dashboard. Dismiss
 * or "Add to role" from the notice makes them known for good; nothing is granted on its own.
 * Installed: staff "admin" ['*'] (Administrator); the first look at the known list leaves
 * redirects.manage fresh. The config user editor@ manages roles below it, keeper@ only sees them.
 */
final class NewPermissionsTest extends TestCase
{
    use RbacAppScaffold;

    private const array EDITOR = ['folio.dashboard', 'rbac_role.*', 'page.*'];

    protected function setUp(): void
    {
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function managerSetup(): void
    {
        $this->install();
        $this->role('r-editor', 'staff', 'editor', self::EDITOR);
        $this->role('r-helper', 'staff', 'helper', ['page.view']);
        $this->role('r-keeper', 'staff', 'keeper', ['folio.dashboard', 'rbac_role.view']);
    }

    private function known(): KnownPermissions
    {
        return $this->container()->get(KnownPermissions::class);
    }

    /**
     * Makes declared permissions fresh again, as if an update had just brought them.
     *
     * @param list<string> $names
     */
    private function forget(array $names): void
    {
        $this->known()->fresh(); // the baseline first
        $dm = $this->container()->get(DataManager::class);
        foreach ($names as $name) {
            $dm->deleteIn(KnownPermissions::definition(), $name);
        }
    }

    private static function text(ResponseInterface $res): string
    {
        return html_entity_decode((string) $res->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return list<string> the notice's lines as plain text, whitespace collapsed */
    private function lines(string $html): array
    {
        preg_match_all('#<li class="rbac-new-item"[^>]*>(.*?)</li>#s', $html, $m);

        return array_map(static fn (string $line): string => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), $m[1]);
    }

    private static function badge(string $html): ?string
    {
        return preg_match('#<span>Roles</span><span class="folio-nav-badge"[^>]*>(\d+)</span>#', $html, $m) === 1 ? $m[1] : null;
    }

    #[RunInSeparateProcess]
    public function test_badge_shows_the_count_to_a_role_manager(): void
    {
        $this->managerSetup();
        $this->forget(['assets.view', 'assets.upload']);
        $this->as('editor');

        $this->assertSame('3', self::badge((string) $this->send('GET', '/folio')->getBody()));
    }

    #[RunInSeparateProcess]
    public function test_no_badge_notice_or_dashboard_line_without_rbac_role_edit(): void
    {
        $this->managerSetup();
        $this->as('keeper');

        $dashboard = (string) $this->send('GET', '/folio')->getBody();
        $this->assertStringContainsString('<span>Roles</span>', $dashboard, 'keeper sees the Roles item');
        $this->assertNull(self::badge($dashboard));
        $this->assertStringNotContainsString('in Roles</span>', $this->needsPart());
        $list = $this->send('GET', '/folio/rbac_role');
        $this->assertSame(200, $list->getStatusCode());
        $this->assertStringNotContainsString('rbac-new', (string) $list->getBody());
        $this->assertSame(403, $this->send('POST', '/folio/rbac/new-permissions/dismiss', ['names' => ['redirects.manage']])->getStatusCode());
        $this->assertSame(['redirects.manage'], $this->known()->fresh());
    }

    #[RunInSeparateProcess]
    public function test_notice_lists_one_line_per_group_with_the_roles_covering_it(): void
    {
        $this->managerSetup();
        $this->role('r-content', 'staff', 'content', ['assets.*']);
        $this->forget(['page.view', 'page.create', 'page.edit', 'page.publish', 'page.delete', 'assets.upload']);
        $this->as('admin');

        $res = $this->send('GET', '/folio/rbac_role');
        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        $text = self::text($res);
        $this->assertStringContainsString('New permissions', $text);
        $this->assertStringContainsString('These permissions came with an update. They are not given to any role automatically.', $text);

        $this->assertStringContainsString('<ul class="folio-notice-list rbac-new-list">', $html, "Folio's list styling");
        $lines = $this->lines($html);
        $this->assertCount(3, $lines, implode("\n", $lines)); // Folio, Pages (a type's five as one), Assets
        [$assets, $folio, $pages] = [self::lineWith($lines, 'assets.upload'), self::lineWith($lines, 'redirects.manage'), self::lineWith($lines, 'page.view')];
        $this->assertStringContainsString('Redirects', $folio);
        $this->assertStringContainsString('Already in: Administrator (*)', $folio);
        foreach (['page.create', 'page.edit', 'page.publish', 'page.delete'] as $name) {
            $this->assertStringContainsString($name, $pages);
        }
        $this->assertStringContainsString('Administrator (*), Editor (page.*)', $pages);
        $this->assertStringContainsString('Administrator (*), Content (assets.*)', $assets);
        $this->assertStringNotContainsString('Helper (', $assets, 'helper does not cover it');
        // Each line: "Add to role…" with the roles, Add and Dismiss, posting its names with the CSRF token.
        $this->assertSame(3, substr_count($html, 'action="/folio/rbac/new-permissions/dismiss"'));
        $this->assertSame(3, substr_count($html, 'action="/folio/rbac/new-permissions/grant"'));
        $this->assertMatchesRegularExpression('#<input type="hidden" name="names\[\]" value="redirects.manage">#', $html);
        $this->assertMatchesRegularExpression('#<input type="hidden" name="_csrf_token" value="[^"]+">#', $html);
        $this->assertStringContainsString('Add to role…', $text);
        $this->assertMatchesRegularExpression('#<option value="r-helper">Helper</option>#', $html);
        $this->assertStringContainsString('>Dismiss</button>', $html);
        $this->assertStringContainsString('>Add</button>', $html);
        // Shown, not granted.
        $this->assertSame(['page.view'], $this->stored('rbac_role', 'r-helper')?->get('permissions'));
    }

    /** @param list<string> $lines */
    private static function lineWith(array $lines, string $needle): string
    {
        foreach ($lines as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }
        self::fail("no line with {$needle}: " . implode(' | ', $lines));
    }

    #[RunInSeparateProcess]
    public function test_picker_offers_only_roles_the_user_may_give_the_permissions(): void
    {
        $this->managerSetup();
        $this->forget(['page.view', 'page.create', 'page.edit', 'page.publish', 'page.delete']);
        $this->as('editor');

        $html = (string) $this->send('GET', '/folio/rbac_role')->getBody();
        $lines = $this->lines($html);
        $this->assertCount(2, $lines);
        // The editor holds page.* (Pages: Helper; their own role has them all already) but not
        // redirects.manage (no picker on that line).
        $this->assertSame(1, substr_count($html, 'action="/folio/rbac/new-permissions/grant"'));
        $this->assertMatchesRegularExpression('#<option value="r-helper">Helper</option>#', $html);
        $this->assertDoesNotMatchRegularExpression('#<option value="r-editor">Editor</option>#', $html);
        $this->assertDoesNotMatchRegularExpression('#<option value="[^"]*">Administrator</option>#', $html);
        // Dismiss only where the editor holds the permissions: the Pages line, not Redirects.
        $this->assertSame(1, substr_count($html, 'action="/folio/rbac/new-permissions/dismiss"'));
    }

    #[RunInSeparateProcess]
    public function test_picker_offers_staff_roles_that_lack_some_of_the_line(): void
    {
        $this->managerSetup();
        $this->role('r-content', 'staff', 'content', ['assets.*']);
        $this->role('r-member', 'members', 'member', ['page.view']);
        $this->forget(['page.view', 'page.create', 'page.edit', 'page.publish', 'page.delete', 'assets.upload']);
        $this->as('admin');

        $items = $this->items((string) $this->send('GET', '/folio/rbac_role')->getBody());
        $this->assertCount(3, $items);
        $options = static function (string $item): array {
            preg_match_all('#<option value="([^"]+)">#', $item, $m);
            sort($m[1]);

            return $m[1];
        };
        // A role covering every name of the line (Administrator by *, Editor by page.*, Content by
        // assets.*) is not offered; neither is a members role, the admin's notice is for staff roles.
        $this->assertSame(['r-content', 'r-editor', 'r-helper', 'r-keeper'], $options(self::itemWith($items, 'redirects.manage')));
        $this->assertSame(['r-content', 'r-helper', 'r-keeper'], $options(self::itemWith($items, 'page.view')));
        $this->assertSame(['r-editor', 'r-helper', 'r-keeper'], $options(self::itemWith($items, 'assets.upload')));
    }

    /** @return list<string> the notice's lines as HTML */
    private function items(string $html): array
    {
        preg_match_all('#<li class="rbac-new-item"[^>]*>(.*?)</li>#s', $html, $m);

        return $m[1];
    }

    /** @param list<string> $items */
    private static function itemWith(array $items, string $needle): string
    {
        foreach ($items as $item) {
            if (str_contains($item, '<code>' . $needle . '</code>')) {
                return $item;
            }
        }
        self::fail("no line with {$needle}");
    }

    #[RunInSeparateProcess]
    public function test_dashboard_line_for_a_role_manager(): void
    {
        $this->managerSetup();
        $this->as('editor');

        $html = $this->needsPart();
        // A "Needs you" card (0.76; before, a notice line), linking to the Roles list.
        $this->assertMatchesRegularExpression('#<a class="folio-needs-card" data-tone="warning" href="/folio/rbac_role">.*?\#ui-shield".*?<span class="folio-needs-title">1 new permission in Roles</span><span class="folio-needs-text">An update brought them. Decide per role.</span>#s', $html);
    }

    #[RunInSeparateProcess]
    public function test_dismiss_clears_notice_badge_and_dashboard_for_good(): void
    {
        $this->managerSetup();
        $this->as('admin');

        $res = $this->send('POST', '/folio/rbac/new-permissions/dismiss', ['names' => ['redirects.manage']]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/rbac_role', $res->getHeaderLine('Location'));

        $list = (string) $this->send('GET', '/folio/rbac_role')->getBody();
        $this->assertStringContainsString('Dismissed.', $list);
        $this->assertStringNotContainsString('rbac-new', $list);
        $this->assertNull(self::badge($list));
        $this->assertStringNotContainsString('in Roles</span>', $this->needsPart());
        $this->assertSame([], $this->known()->fresh());
        $this->assertSame([], (new KnownPermissions($this->container()->get(DataManager::class), $this->container()->get(Access::class)->registry()))->fresh(), 'stored, not remembered');
        $this->assertSame(['*'], $this->roles()[array_key_first(array_filter($this->roles(), static fn ($r): bool => $r->get('name') === 'admin'))]->get('permissions'));
    }

    #[RunInSeparateProcess]
    public function test_dismiss_only_for_permissions_the_user_holds(): void
    {
        $this->managerSetup();
        $this->as('editor'); // manages roles, doesn't hold redirects.manage

        $list = (string) $this->send('GET', '/folio/rbac_role')->getBody();
        $this->assertStringContainsString('rbac-new', $list, 'the notice is still shown');
        $this->assertStringNotContainsString('/folio/rbac/new-permissions/dismiss', $list, 'no Dismiss for a permission the user lacks');

        $res = $this->send('POST', '/folio/rbac/new-permissions/dismiss', ['names' => ['redirects.manage']]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(['redirects.manage'], $this->known()->fresh(), 'a crafted dismiss leaves it for those who hold it');
        $this->assertStringNotContainsString('Dismissed.', (string) $this->send('GET', '/folio/rbac_role')->getBody());

        // A mixed post: what the editor holds (page.view) is dismissed, the rest stays.
        $this->forget(['page.view']);
        $this->send('POST', '/folio/rbac/new-permissions/dismiss', ['names' => ['page.view', 'redirects.manage']]);
        $this->assertSame(['redirects.manage'], $this->known()->fresh());
    }

    #[RunInSeparateProcess]
    public function test_add_to_role_grants_the_exact_names_and_makes_them_known(): void
    {
        $this->managerSetup();
        $this->as('admin');

        $res = $this->send('POST', '/folio/rbac/new-permissions/grant', ['names' => ['redirects.manage'], 'role' => 'r-helper']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/rbac_role', $res->getHeaderLine('Location'));
        $this->assertSame(['page.view', 'redirects.manage'], $this->stored('rbac_role', 'r-helper')?->get('permissions'));
        $this->assertSame([], $this->known()->fresh());
        $list = self::text($this->send('GET', '/folio/rbac_role'));
        $this->assertStringContainsString('Added to Helper.', $list);
        $this->assertStringNotContainsString('rbac-new', $list);
    }

    #[RunInSeparateProcess]
    public function test_add_to_role_obeys_delegation(): void
    {
        $this->managerSetup();
        $this->as('editor');

        $res = $this->send('POST', '/folio/rbac/new-permissions/grant', ['names' => ['redirects.manage'], 'role' => 'r-helper']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/rbac_role', $res->getHeaderLine('Location'));
        $this->assertStringContainsString("You can't grant permissions you don't have: redirects.manage", self::text($this->send('GET', '/folio/rbac_role')));
        $this->assertSame(['page.view'], $this->stored('rbac_role', 'r-helper')?->get('permissions'));
        $this->assertSame(['redirects.manage'], $this->known()->fresh());
    }

    #[RunInSeparateProcess]
    public function test_add_to_role_without_a_role_or_with_names_not_new(): void
    {
        $this->managerSetup();
        $this->as('admin');

        $this->assertSame(302, $this->send('POST', '/folio/rbac/new-permissions/grant', ['names' => ['redirects.manage'], 'role' => ''])->getStatusCode());
        $this->assertStringContainsString('Choose a role to add it to.', self::text($this->send('GET', '/folio/rbac_role')));
        $this->assertSame(['redirects.manage'], $this->known()->fresh());

        // Only new permissions are given from the notice: folio.cache is known already.
        $this->assertSame(302, $this->send('POST', '/folio/rbac/new-permissions/grant', ['names' => ['folio.cache', 'redirects.manage'], 'role' => 'r-helper'])->getStatusCode());
        $this->assertSame(['page.view', 'redirects.manage'], $this->stored('rbac_role', 'r-helper')?->get('permissions'));
    }

    #[RunInSeparateProcess]
    public function test_add_to_role_runs_the_types_save_hooks_as_the_role_editor_does(): void
    {
        // Another extension's customization of rbac_role: refuses saving "helper", records every save.
        $root = $this->dir . '/app/Folio/';
        $autoload = static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\Folio\\')) {
                $file = $root . str_replace('\\', '/', substr($class, strlen('App\\Folio\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($autoload);
        try {
            $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": []}, "audit": {"disabled_parts": []}}}');
            $this->write('app/Folio/Audit/Extension.php', <<<'PHP'
                <?php
                namespace App\Folio\Audit;
                #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'audit', label: 'Audit')]
                final class Extension extends \Preflow\Folio\Extension\Extension {}
                PHP);
            $this->write('app/Folio/Audit/Types/RoleAudit.php', <<<'PHP'
                <?php
                namespace App\Folio\Audit\Types;
                use Preflow\Data\DynamicRecord;
                #[\Preflow\Folio\Extension\Attributes\FolioType('rbac_role')]
                final class RoleAudit
                {
                    #[\Preflow\Folio\Extension\BeforeSave]
                    public function before(DynamicRecord $role): void
                    {
                        if ($role->get('name') === 'helper') {
                            throw new \Preflow\Folio\Extension\Customization\Refused('Helper is frozen.');
                        }
                    }

                    #[\Preflow\Folio\Extension\AfterSave]
                    public function after(DynamicRecord $role): void
                    {
                        file_put_contents(dirname(__DIR__, 4) . '/storage/audit.log', $role->get('name') . ': ' . implode(',', $role->get('permissions')) . "\n", FILE_APPEND);
                    }
                }
                PHP);
            $this->managerSetup();
            $this->as('admin');

            $this->assertSame(302, $this->send('POST', '/folio/rbac/new-permissions/grant', ['names' => ['redirects.manage'], 'role' => 'r-helper'])->getStatusCode());
            $this->assertStringContainsString('Helper is frozen.', self::text($this->send('GET', '/folio/rbac_role')));
            $this->assertSame(['page.view'], $this->stored('rbac_role', 'r-helper')?->get('permissions'));
            $this->assertSame(['redirects.manage'], $this->known()->fresh());
            $this->assertFileDoesNotExist($this->dir . '/storage/audit.log');

            $this->assertSame(302, $this->send('POST', '/folio/rbac/new-permissions/grant', ['names' => ['redirects.manage'], 'role' => 'r-editor'])->getStatusCode());
            $this->assertSame([...self::EDITOR, 'redirects.manage'], $this->stored('rbac_role', 'r-editor')?->get('permissions'));
            $this->assertSame("editor: folio.dashboard,rbac_role.*,page.*,redirects.manage\n", (string) file_get_contents($this->dir . '/storage/audit.log'));
            $this->assertSame([], $this->known()->fresh());
        } finally {
            spl_autoload_unregister($autoload);
        }
    }

    #[RunInSeparateProcess]
    public function test_add_to_role_whose_save_fails_answers_with_a_message_not_a_500(): void
    {
        $this->managerSetup();
        $this->as('admin');
        // The role's file can't be written (the save throws after the delegation check passed).
        $dir = $this->dir . '/storage/data/rbac_role';
        chmod($dir, 0555);
        set_error_handler(static fn (): bool => true, E_WARNING); // the driver's own warning, before it throws
        try {
            $res = $this->send('POST', '/folio/rbac/new-permissions/grant', ['names' => ['redirects.manage'], 'role' => 'r-helper']);
        } finally {
            restore_error_handler();
            chmod($dir, 0755);
        }
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/rbac_role', $res->getHeaderLine('Location'));
        $this->assertStringContainsString('The role could not be saved. The error is in the server log.', self::text($this->send('GET', '/folio/rbac_role')));
        $this->assertSame(['page.view'], $this->stored('rbac_role', 'r-helper')?->get('permissions'));
        $this->assertSame(['redirects.manage'], $this->known()->fresh(), 'still new');
        $this->assertNotEmpty(array_filter($this->errorLog(), static fn (string $line): bool => str_contains($line, 'rbac: add to role failed')), implode("\n", $this->errorLog()));
    }

    #[RunInSeparateProcess]
    public function test_migrate_lists_the_new_permissions_where_rbac_shows_them(): void
    {
        $steps = $this->container()->get(\Preflow\Data\Migration\MigrationRegistry::class)->steps();

        $this->assertSame(['New permissions to review in Settings → Roles: redirects.manage (Redirects)'], $steps['folio.permissions']());
    }

    #[RunInSeparateProcess]
    public function test_granting_in_the_role_editor_keeps_the_notice_and_names_the_role(): void
    {
        $this->managerSetup();
        $this->as('admin');

        $save = $this->send('POST', '/folio/rbac_role/r-helper', ['name' => 'helper', 'label' => 'Helper', 'population' => 'staff', 'permissions' => ['', 'page.view', 'redirects.manage']]);
        $this->assertSame(302, $save->getStatusCode());
        $this->assertSame(['page.view', 'redirects.manage'], $this->stored('rbac_role', 'r-helper')?->get('permissions'));

        $lines = $this->lines((string) $this->send('GET', '/folio/rbac_role')->getBody());
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Already in: Administrator (*), Helper (redirects.manage)', $lines[0]);
        $this->assertSame(['redirects.manage'], $this->known()->fresh());
    }

    #[RunInSeparateProcess]
    public function test_site_whose_known_list_has_no_table_shows_nothing_and_never_500s(): void
    {
        $this->managerSetup();
        $this->as('admin');
        // A SQLite site upgraded but not migrated: Folio's internal storage has no folio_known_permissions.
        $c = $this->container();
        $dm = $c->get(DataManager::class);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $bare = new DataManager(['json' => $dm->driver('json'), 'default' => new \Preflow\Data\Driver\SqliteDriver($pdo)], 'json', $dm->getTypeRegistry());
        $c->instance(KnownPermissions::class, new KnownPermissions($bare, $c->get(Access::class)->registry()));

        $list = $this->send('GET', '/folio/rbac_role');
        $this->assertSame(200, $list->getStatusCode());
        $this->assertStringNotContainsString('rbac-new', (string) $list->getBody());
        $this->assertNull(self::badge((string) $list->getBody()));
        $this->assertNotEmpty(array_filter($this->errorLog(), static fn (string $line): bool => str_contains($line, 'rbac: new permissions not shown')), 'logged, not shown');
        $dashboard = $this->send('GET', '/folio');
        $this->assertSame(200, $dashboard->getStatusCode());
        $this->assertStringNotContainsString('in Roles</span>', $this->needsPart());

        foreach (['dismiss' => ['names' => ['redirects.manage']], 'grant' => ['names' => ['redirects.manage'], 'role' => 'r-helper']] as $action => $body) {
            $res = $this->send('POST', '/folio/rbac/new-permissions/' . $action, $body);
            $this->assertSame(302, $res->getStatusCode(), $action);
            $this->assertStringContainsString("New permissions can't be checked yet: run preflow migrate.", self::text($this->send('GET', '/folio/rbac_role')), $action);
        }
        $this->assertSame(['page.view'], $this->stored('rbac_role', 'r-helper')?->get('permissions'));
    }

    /** The dashboard's "Needs you" part, as its htmx placeholder fetches it after the page. */
    private function needsPart(): string
    {
        $request = (new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('GET', '/folio?part=needs')
            ->withHeader('HX-Request', 'true')
            ->withQueryParams(['part' => 'needs']);
        $response = $this->session->handle($request);
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }
}
