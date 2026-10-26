<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\Customization\Refused;
use Preflow\Rbac\Types\StaffRules;
use Preflow\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;

/**
 * Staff accounts (rbac's opt-in model "staff") with delegated roles. Signed in through a chain
 * (staff records, then the config users). Roles: admin ['*'], manager (staff and roles: a
 * manager account), editor (may edit staff, not roles), helper (pages, view). Accounts:
 * boss@ (admin), ed@ (editor), help@ (helper), none@ (no roles); mgr@ (manager) with $twoManagers.
 */
final class StaffAccountsTest extends TestCase
{
    use RbacAppScaffold;

    private const string GRANT = "You can't grant permissions you don't have: ";
    private const string ACCOUNT = "You can't change an account with roles you can't grant.";
    private const string LAST = 'At least one account must keep the right to manage staff and roles.';

    protected function setUp(): void
    {
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** The site switches the staff model on and signs staff in through the chain. */
    private function staffSite(): void
    {
        $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}}}');
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'chain', 'population' => 'staff']],
            'providers' => [
                'staff' => ['class' => \Preflow\Auth\Record\RecordUserProvider::class, 'type' => 'staff', 'active' => 'active'],
                'config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                    ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ]],
                'chain' => ['class' => \Preflow\Auth\ChainUserProvider::class, 'providers' => ['staff', 'config']],
            ],
            'session' => ['cookie' => 'rbac_staff_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
    }

    private function setupAccounts(bool $twoManagers = false): void
    {
        $this->staffSite();
        $this->install();
        $this->role('r-manager', 'staff', 'manager', ['folio.dashboard', 'staff.*', 'rbac_role.*']);
        $this->role('r-editor', 'staff', 'editor', ['folio.dashboard', 'staff.*', 'page.*']);
        $this->role('r-helper', 'staff', 'helper', ['page.view']);
        $this->account('s-boss', 'boss', ['admin']);
        $this->account('s-ed', 'ed', ['editor']);
        $this->account('s-help', 'help', ['helper']);
        $this->account('s-none', 'none', []);
        if ($twoManagers) {
            $this->account('s-mgr', 'mgr', ['manager']);
        }
    }

    /** @param list<string> $roles */
    private function account(string $id, string $name, array $roles, bool $active = true): void
    {
        $dm = $this->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('staff'), [
            'uuid' => $id, 'email' => "{$name}@site.test", 'name' => ucfirst($name), 'roles' => $roles, 'active' => $active,
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT), 'auth_epoch' => '0',
        ]), false);
    }

    /** @param list<string> $roles @param array<string, string> $more */
    private function saveAccount(string $id, string $name, array $roles, array $more = []): ResponseInterface
    {
        $stored = $this->stored('staff', $id);

        return $this->send('POST', '/folio/staff/' . $id, $more + [
            'email' => (string) ($stored?->get('email') ?? "{$name}@site.test"),
            'name' => (string) ($stored?->get('name') ?? ucfirst($name)),
            'roles' => ['', ...$roles],
            'active' => ($stored?->get('active') ?? true) ? '1' : '0',
        ]);
    }

    private static function text(ResponseInterface $res): string
    {
        return html_entity_decode((string) $res->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return list<string> */
    private function rolesOf(string $id): array
    {
        return \Preflow\Rbac\RoleList::normalize($this->stored('staff', $id)?->get('roles'));
    }

    #[RunInSeparateProcess]
    public function test_assign_and_remove_a_covered_role(): void
    {
        $this->setupAccounts();
        $this->as('ed');

        $this->assertSame(302, $this->saveAccount('s-none', 'none', ['helper'])->getStatusCode());
        $this->assertSame(['helper'], $this->rolesOf('s-none'));
        $this->assertSame(302, $this->saveAccount('s-none', 'none', [])->getStatusCode());
        $this->assertSame([], $this->rolesOf('s-none'));
    }

    #[RunInSeparateProcess]
    public function test_assigning_an_uncovered_role_refused(): void
    {
        $this->setupAccounts();
        $this->as('ed');

        // A crafted POST: the admin role (*) isn't offered to the editor.
        $res = $this->saveAccount('s-help', 'help', ['helper', 'admin']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString(self::GRANT . '*', self::text($res));
        $this->assertSame(['helper'], $this->rolesOf('s-help'));

        $res = $this->saveAccount('s-help', 'help', ['helper', 'manager']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString(self::GRANT . 'rbac_role.*', self::text($res));

        // Creating one with it neither.
        $res = $this->send('POST', '/folio/staff', ['email' => 'new@site.test', 'name' => 'New', 'roles' => ['', 'admin'], 'active' => '1']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString(self::GRANT . '*', self::text($res));
        $this->assertCount(4, $this->container()->get(DataManager::class)->queryType('staff')->get()->items());
    }

    #[RunInSeparateProcess]
    public function test_adding_an_undefined_role_name_refused(): void
    {
        $this->setupAccounts();
        $this->as('ed');

        // Squatting a name no staff role has (yet): it would grant whatever a role of that name gets later.
        $res = $this->saveAccount('s-ed', 'ed', ['editor', 'owner']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Unknown role: owner.', self::text($res));
        $this->assertSame(['editor'], $this->rolesOf('s-ed'));

        // A member role's name is not a staff role either.
        $this->role('r-m', 'members', 'club', []);
        $this->assertSame(422, $this->saveAccount('s-help', 'help', ['helper', 'club'])->getStatusCode());

        // Nor the name of a role that was deleted.
        $this->container()->get(DataManager::class)->deleteType('rbac_role', 'r-helper');
        $this->assertNull($this->stored('rbac_role', 'r-helper'));
        $res = $this->saveAccount('s-none', 'none', ['helper']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Unknown role: helper.', self::text($res));
        $this->assertSame([], $this->rolesOf('s-none'));

        // A stored name that is no longer defined may stay on an unrelated edit.
        $this->assertSame(302, $this->saveAccount('s-help', 'help', ['helper'], ['name' => 'Still Help'])->getStatusCode());
        $this->assertSame(['helper'], $this->rolesOf('s-help'));
        $this->assertSame('Still Help', $this->stored('staff', 's-help')?->get('name'));
    }

    #[RunInSeparateProcess]
    public function test_editing_an_account_above_you_refused(): void
    {
        $this->setupAccounts(twoManagers: true);
        $this->as('ed');

        $res = $this->saveAccount('s-mgr', 'mgr', ['manager'], ['name' => 'Renamed']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString(self::ACCOUNT, self::text($res));
        $this->assertSame('Mgr', $this->stored('staff', 's-mgr')?->get('name'));

        // Nor removing a role from it (the editor covers none of manager).
        $this->assertSame(422, $this->saveAccount('s-mgr', 'mgr', [])->getStatusCode());
        $this->assertSame(['manager'], $this->rolesOf('s-mgr'));

        // Nor deleting it.
        $this->assertSame(302, $this->send('POST', '/folio/staff/s-mgr/delete')->getStatusCode());
        $this->assertNotNull($this->stored('staff', 's-mgr'));
        $this->assertStringContainsString(self::ACCOUNT, self::text($this->send('GET', '/folio/staff')));

        // Viewing stays allowed.
        $this->assertSame(200, $this->send('GET', '/folio/staff/s-mgr/edit')->getStatusCode());
        // An account within reach is fine to edit.
        $this->assertSame(302, $this->saveAccount('s-help', 'help', ['helper'], ['name' => 'Helpful'])->getStatusCode());
        $this->assertSame('Helpful', $this->stored('staff', 's-help')?->get('name'));
    }

    #[RunInSeparateProcess]
    public function test_the_last_manager_cant_demote_disable_or_delete_themselves(): void
    {
        $this->setupAccounts();
        $this->as('boss');

        $res = $this->saveAccount('s-boss', 'boss', ['helper']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString(self::LAST, self::text($res));
        $this->assertSame(['admin'], $this->rolesOf('s-boss'));

        $res = $this->saveAccount('s-boss', 'boss', ['admin'], ['active' => '0']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString(self::LAST, self::text($res));
        $this->assertTrue((bool) $this->stored('staff', 's-boss')?->get('active'));

        $this->assertSame(302, $this->send('POST', '/folio/staff/s-boss/delete')->getStatusCode());
        $this->assertNotNull($this->stored('staff', 's-boss'));
        $this->assertStringContainsString(self::LAST, self::text($this->send('GET', '/folio/staff')));

        // Other fields of the last manager stay editable.
        $this->assertSame(302, $this->saveAccount('s-boss', 'boss', ['admin'], ['name' => 'The Boss'])->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_with_two_managers_one_may_go(): void
    {
        $this->setupAccounts(twoManagers: true);
        $this->as('boss');

        $this->assertSame(302, $this->saveAccount('s-mgr', 'mgr', ['helper'])->getStatusCode());
        $this->assertSame(['helper'], $this->rolesOf('s-mgr'));

        // Now boss is the last one.
        $this->assertSame(422, $this->saveAccount('s-boss', 'boss', [])->getStatusCode());
        $this->assertSame(302, $this->saveAccount('s-mgr', 'mgr', ['manager'])->getStatusCode());
        $this->assertSame(302, $this->send('POST', '/folio/staff/s-mgr/delete')->getStatusCode());
        $this->assertNull($this->stored('staff', 's-mgr'));
    }

    #[RunInSeparateProcess]
    public function test_create_through_the_form(): void
    {
        $this->setupAccounts();
        $this->as('ed');

        $form = (string) $this->send('GET', '/folio/staff/new')->getBody();
        $this->assertMatchesRegularExpression('#<input type="checkbox"[^>]*name="active"[^>]*checked#', $form, 'active by default');

        $res = $this->send('POST', '/folio/staff', ['email' => ' New@Site.TEST ', 'name' => 'New', 'roles' => ['', 'helper'], 'active' => '1', 'auth_epoch' => '9', 'created_at' => '2000-01-01']);
        $this->assertSame(302, $res->getStatusCode(), self::text($res));
        $new = $this->container()->get(DataManager::class)->queryType('staff')->where('email', 'new@site.test')->first();
        $this->assertInstanceOf(DynamicRecord::class, $new);
        $this->assertTrue((bool) $new->get('active'));
        $this->assertSame('0', (string) $new->get('auth_epoch'));
        $this->assertNotSame('2000-01-01', (string) $new->get('created_at'));
        $this->assertNotFalse(strtotime((string) $new->get('created_at')));
        $this->assertSame(['helper'], \Preflow\Rbac\RoleList::normalize($new->get('roles')));

        // The address is unique, compared lowercased.
        $res = $this->send('POST', '/folio/staff', ['email' => 'HELP@site.test', 'name' => 'Twin', 'roles' => [''], 'active' => '1']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Another account already uses this email address.', self::text($res));
    }

    #[RunInSeparateProcess]
    public function test_an_email_of_a_configured_admin_is_refused_in_the_admin(): void
    {
        $this->setupAccounts();
        $this->as('ed');
        $refusal = 'This e-mail address belongs to a configured admin.';

        $res = $this->send('POST', '/folio/staff', ['email' => ' Admin@Site.test ', 'name' => 'Takeover', 'roles' => [''], 'active' => '0']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString($refusal, self::text($res));
        $res = $this->send('POST', '/folio/staff/s-help', ['email' => 'admin@site.test', 'name' => 'Help', 'roles' => ['', 'helper'], 'active' => '1']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString($refusal, self::text($res));
        $this->assertSame('help@site.test', $this->container()->get(DataManager::class)->findType('staff', 's-help')?->get('email'));

        // An imported account (its e-mail was the config admin's from the start) stays editable.
        $this->account('s-imported', 'admin', ['helper']);
        $res = $this->send('POST', '/folio/staff/s-imported', ['email' => 'admin@site.test', 'name' => 'Imported', 'roles' => ['', 'helper'], 'active' => '1']);
        $this->assertSame(302, $res->getStatusCode(), self::text($res));
    }

    #[RunInSeparateProcess]
    public function test_roles_field_disables_what_the_editor_cant_grant(): void
    {
        $this->setupAccounts(twoManagers: true);
        $this->as('ed');

        $form = (string) $this->send('GET', '/folio/staff/s-help/edit')->getBody();
        $denied = static fn (string $role): string => '#<input type="checkbox" name="roles\[\]" value="' . $role . '"[^>]* disabled data-rbac-denied#';
        $this->assertMatchesRegularExpression($denied('admin'), $form);
        $this->assertMatchesRegularExpression($denied('manager'), $form);
        $this->assertDoesNotMatchRegularExpression($denied('helper'), $form);
        $this->assertDoesNotMatchRegularExpression($denied('editor'), $form);
        $this->assertMatchesRegularExpression('#value="helper"[^>]* checked#', $form);
    }

    #[RunInSeparateProcess]
    public function test_without_a_signed_in_editor_only_lockout_and_email_apply(): void
    {
        $this->setupAccounts();
        $rules = $this->container()->get(StaffRules::class);
        $dm = $this->container()->get(DataManager::class);

        // The CLI may grant anything.
        $help = $this->stored('staff', 's-help');
        $help->set('roles', ['helper', 'admin']);
        $rules->beforeSave($help);
        $dm->saveType($help, false);

        // A new account written by code: active, epoch "0" and created_at filled in.
        $new = DynamicRecord::fromArray($dm->getTypeRegistry()->get('staff'), ['uuid' => 's-new', 'email' => 'Code@Site.test', 'roles' => []]);
        $rules->beforeSave($new);
        $this->assertTrue($new->get('active'));
        $this->assertSame('0', $new->get('auth_epoch'));
        $this->assertSame('code@site.test', $new->get('email'));
        $this->assertNotEmpty($new->get('created_at'));

        try {
            $squat = $this->stored('staff', 's-none');
            $squat->set('roles', ['owner']);
            $rules->beforeSave($squat);
            $this->fail('undefined role accepted without a signed-in user');
        } catch (ValidationException $e) {
            $this->assertSame(['Unknown role: owner.'], $e->errors()['roles'] ?? null);
        }

        try {
            $twin = DynamicRecord::fromArray($dm->getTypeRegistry()->get('staff'), ['uuid' => 's-twin', 'email' => 'boss@SITE.test']);
            $rules->beforeSave($twin);
            $this->fail('duplicate e-mail accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        // Lock-out still applies: boss and help both manage now; both can't go.
        $boss = $this->stored('staff', 's-boss');
        $boss->set('active', false);
        $rules->beforeSave($boss);
        $dm->saveType($boss, false);
        $this->expectException(Refused::class);
        $this->expectExceptionMessage(self::LAST);
        $rules->beforeDelete($this->stored('staff', 's-help'));
    }

    #[RunInSeparateProcess]
    public function test_role_edit_that_leaves_no_manager_refused(): void
    {
        $this->setupAccounts(twoManagers: true);
        $this->as('admin'); // the config admin: holds everything, isn't a staff record

        // Boss is the only admin-role account; mgr the only manager-role one. Taking staff.*
        // from manager leaves boss; then taking * from admin would leave nobody.
        $this->assertSame(302, $this->send('POST', '/folio/rbac_role/r-manager', [
            'name' => 'manager', 'label' => 'Manager', 'population' => 'staff', 'permissions' => ['', 'folio.dashboard', 'rbac_role.*'],
        ])->getStatusCode());
        $admin = '';
        foreach ($this->roles() as $role) {
            $admin = $role->get('name') === 'admin' ? (string) $role->getId() : $admin;
        }
        $res = $this->send('POST', '/folio/rbac_role/' . $admin, [
            'name' => 'admin', 'label' => 'Administrator', 'population' => 'staff', 'permissions' => ['', 'rbac_role.*', 'folio.dashboard'],
        ]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString(self::LAST, self::text($res));
    }

    #[RunInSeparateProcess]
    public function test_a_site_without_the_part_has_no_staff_type(): void
    {
        $this->install();
        $c = $this->container();

        $this->assertFalse($c->get(TypeCatalog::class)->has('staff'));
        $this->assertFalse($c->get(DataManager::class)->getTypeRegistry()->has('staff'));
        $registry = $c->get(Access::class)->registry();
        $this->assertSame([], array_values(array_filter(array_keys($registry->all()), static fn (string $p): bool => str_starts_with($p, 'staff.'))));
        $this->assertFalse(is_dir($this->dir . '/storage/data/staff'));

        $this->as('admin');
        $this->assertSame(404, $this->send('GET', '/folio/staff')->getStatusCode());
        $this->assertStringNotContainsString('/folio/staff', (string) $this->send('GET', '/folio')->getBody());
    }
}
