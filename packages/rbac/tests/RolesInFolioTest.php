<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Roles edited in Folio (Settings → Roles): the permission and role field types and the
 * lock-out protection of RoleRules. Installed: staff "admin" ['*'] (r-admin via install).
 */
final class RolesInFolioTest extends TestCase
{
    use RbacAppScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function adminId(): string
    {
        foreach ($this->roles() as $id => $role) {
            if ($role->get('name') === 'admin' && $role->get('population') === 'staff') {
                return (string) $role->getId();
            }
        }
        $this->fail('no admin role');
    }

    #[RunInSeparateProcess]
    public function test_roles_listed_under_settings(): void
    {
        $this->install();
        $this->as('admin');

        $page = (string) $this->send('GET', '/folio/rbac_role')->getBody();
        $this->assertMatchesRegularExpression(
            '#<span class="folio-nav-label">Settings</span>.*<a href="/folio/rbac_role"[^>]*>\s*<svg class="folio-icon" aria-hidden="true"><use href="[^"]*\#ui-users"></use></svg><span>Roles</span>#s',
            $page,
        );
        $this->assertStringContainsString('Administrator', $page);
    }

    #[RunInSeparateProcess]
    public function test_permissions_field_groups_and_patterns(): void
    {
        $this->install();
        $this->role('r-editor', 'staff', 'editor', ['page.*', 'ghost.view']);
        $this->as('admin');

        $form = (string) $this->send('GET', '/folio/rbac_role/r-editor/edit')->getBody();
        $this->assertMatchesRegularExpression('#<input type="hidden" name="permissions\[\]" value="">#', $form);
        $this->assertMatchesRegularExpression('#<input type="checkbox" class="rbac-switch-input" role="switch" name="permissions\[\]" value="\*" data-rbac-everything><span class="rbac-switch" aria-hidden="true"></span>#', $form);
        // The "Pages" row: its verbs and its whole-type pattern (checked) as "All".
        $this->assertMatchesRegularExpression(
            '#<tr class="rbac-row" data-rbac-row><th scope="row" title="page\.\*">Pages</th><td class="rbac-cell"><input type="checkbox" name="permissions\[\]" value="page\.view"[^>]*></td>.*value="page\.\*" title="page\.\*" aria-label="Pages: All" data-rbac-all checked>#',
            $form,
        );
        $this->assertMatchesRegularExpression('#scope="colgroup">Settings</th></tr>\s*<tr class="rbac-row" data-rbac-row><th scope="row" title="rbac_role\.\*">Roles</th>.*value="rbac_role\.edit"#s', $form);
        $this->assertMatchesRegularExpression('#<span class="rbac-group-label">Assets</span><label class="rbac-group-all" title="assets\.\*"><input type="checkbox" name="permissions\[\]" value="assets\.\*" data-rbac-all> All</label>#', $form);
        // An undeclared stored name: shown as unknown, not as a checkbox.
        $this->assertStringContainsString('Unknown, removed on save: <code>ghost.view</code>', $form);
        $this->assertStringNotContainsString('value="ghost.view"', $form);

        // Saving drops the unknown name, even when posted.
        $res = $this->send('POST', '/folio/rbac_role/r-editor', [
            'name' => 'editor', 'label' => 'Editor', 'population' => 'staff', 'permissions' => ['', 'page.*', 'assets.view', 'ghost.view'],
        ]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(['page.*', 'assets.view'], $this->stored('rbac_role', 'r-editor')?->get('permissions'));

        // None checked: the hidden input posts an empty list.
        $this->send('POST', '/folio/rbac_role/r-editor', ['name' => 'editor', 'label' => 'Editor', 'population' => 'staff', 'permissions' => ['']]);
        $this->assertSame([], $this->stored('rbac_role', 'r-editor')?->get('permissions'));
    }

    #[RunInSeparateProcess]
    public function test_user_roles_field_lists_population_roles(): void
    {
        $this->install();
        $this->role('r-member', 'members', 'member', ['page.view']);
        $this->role('r-supporter', 'members', 'supporter', []);
        $this->as('admin');

        $form = (string) $this->send('GET', '/folio/member/new')->getBody();
        $this->assertMatchesRegularExpression(
            '#<legend>Roles</legend>\s*<input type="hidden" name="roles\[\]" value="">\s*<label class="form-radio"><input type="checkbox" name="roles\[\]" value="member"> Member</label>\s*<label class="form-radio"><input type="checkbox" name="roles\[\]" value="supporter"> Supporter</label>\s*</fieldset>#',
            $form,
        );
        // A type without "users" takes the field config's "population".
        $this->assertMatchesRegularExpression(
            '#<legend>Member roles</legend>\s*<input type="hidden" name="member_roles\[\]" value="">\s*<label class="form-radio"><input type="checkbox" name="member_roles\[\]" value="member"> Member</label>\s*<label class="form-radio"><input type="checkbox" name="member_roles\[\]" value="supporter"> Supporter</label>\s*</fieldset>#',
            (string) $this->send('GET', '/folio/team/new')->getBody(),
        );

        $this->assertSame(302, $this->send('POST', '/folio/member', ['email' => 'm@site.test', 'roles' => ['', 'supporter']])->getStatusCode());
        $member = array_values($this->container()->get(\Preflow\Data\DataManager::class)->queryType('member')->get()->items())[0];
        $this->assertSame(['supporter'], $member->get('roles'));

        $edit = (string) $this->send('GET', '/folio/member/' . $member->getId() . '/edit')->getBody();
        $this->assertStringContainsString('<input type="checkbox" name="roles[]" value="supporter" checked> Supporter', $edit);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function staffRoleFields(): array
    {
        return [
            'field population' => [['fields' => ['staff_roles' => ['type' => 'rbac_roles', 'population' => 'staff']]], 'team.staff_roles'],
            // The type's "users" population counts when the field names none.
            'staff user type' => [['users' => 'staff', 'fields' => ['roles' => ['type' => 'rbac_roles']]], 'team.roles'],
        ];
    }

    /**
     * Staff role fields boot: assigning them is governed by delegation (the staff type's own rules).
     *
     * @param array<string, mixed> $model
     */
    #[RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\DataProvider('staffRoleFields')]
    public function test_rbac_roles_for_staff_population_boots(array $model, string $field): void
    {
        $this->write('config/models/team.json', (string) json_encode(['key' => 'team', 'table' => 'team', 'storage' => 'json', 'id_field' => 'uuid'] + $model));
        $this->install();
        $this->as('admin');

        [, $name] = explode('.', $field);
        $form = (string) $this->send('GET', '/folio/team/new')->getBody();
        $this->assertStringContainsString('<input type="checkbox" name="' . $name . '[]" value="admin"> Administrator', $form);
    }

    #[RunInSeparateProcess]
    public function test_duplicate_role_name_rejected(): void
    {
        $this->install();
        $this->as('admin');

        $res = $this->send('POST', '/folio/rbac_role', ['name' => 'admin', 'label' => 'Second admin', 'population' => 'staff', 'permissions' => ['page.*']]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('A staff role named admin already exists.', (string) $res->getBody());
        $this->assertCount(1, $this->roles());

        // The same name in the other population is another role.
        $this->assertSame(302, $this->send('POST', '/folio/rbac_role', ['name' => 'admin', 'label' => 'Member admin', 'population' => 'members', 'permissions' => []])->getStatusCode());
        $this->assertCount(2, $this->roles());
    }

    #[RunInSeparateProcess]
    public function test_population_must_be_staff_or_members(): void
    {
        $this->install();
        $this->as('admin');

        $this->assertSame(422, $this->send('POST', '/folio/rbac_role', ['name' => 'odd', 'label' => 'Odd', 'population' => 'robots', 'permissions' => []])->getStatusCode());
        $this->assertCount(1, $this->roles());
    }

    #[RunInSeparateProcess]
    public function test_save_removing_own_role_edit_refused(): void
    {
        $this->install();
        $admin = $this->adminId();
        $this->as('admin');

        $res = $this->send('POST', '/folio/rbac_role/' . $admin, ['name' => 'admin', 'label' => 'Administrator', 'population' => 'staff', 'permissions' => ['page.*']]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('You would lose the right to edit roles.', (string) $res->getBody());
        $this->assertSame(['*'], $this->stored('rbac_role', $admin)?->get('permissions'));

        // Renaming the role away from the user's role name loses it too.
        $this->assertSame(422, $this->send('POST', '/folio/rbac_role/' . $admin, ['name' => 'boss', 'label' => 'Boss', 'population' => 'staff', 'permissions' => ['*']])->getStatusCode());
        $this->assertSame('admin', $this->stored('rbac_role', $admin)?->get('name'));

        // Keeping rbac_role.edit is fine.
        $this->assertSame(302, $this->send('POST', '/folio/rbac_role/' . $admin, ['name' => 'admin', 'label' => 'Administrator', 'population' => 'staff', 'permissions' => ['page.*', 'rbac_role.*']])->getStatusCode());
        $this->assertSame(['page.*', 'rbac_role.*'], $this->stored('rbac_role', $admin)?->get('permissions'));
    }

    #[RunInSeparateProcess]
    public function test_save_removing_own_role_view_refused(): void
    {
        $this->install();
        $admin = $this->adminId();
        $this->as('admin');

        // Editing roles without seeing them (list and edit form need rbac_role.view) locks out too.
        $res = $this->send('POST', '/folio/rbac_role/' . $admin, ['name' => 'admin', 'label' => 'Administrator', 'population' => 'staff', 'permissions' => ['page.*', 'rbac_role.edit']]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('You would lose the right to edit roles.', (string) $res->getBody());
        $this->assertSame(['*'], $this->stored('rbac_role', $admin)?->get('permissions'));

        // Keeping both is fine.
        $this->assertSame(302, $this->send('POST', '/folio/rbac_role/' . $admin, ['name' => 'admin', 'label' => 'Administrator', 'population' => 'staff', 'permissions' => ['page.*', 'rbac_role.view', 'rbac_role.edit']])->getStatusCode());
        $this->assertSame(['page.*', 'rbac_role.view', 'rbac_role.edit'], $this->stored('rbac_role', $admin)?->get('permissions'));
    }

    #[RunInSeparateProcess]
    public function test_last_staff_view_grant_cannot_go(): void
    {
        $this->install();
        $this->role('r-editor', 'staff', 'editor', ['rbac_role.view']);
        $c = $this->container(); // nobody signed in: only the staff-wide check applies
        $rules = $c->get(\Preflow\Rbac\Types\RoleRules::class);
        $dm = $c->get(\Preflow\Data\DataManager::class);
        $admin = $dm->findType('rbac_role', $this->adminId());

        // The admin role keeps edit but drops view; the editor role still grants view.
        $admin->set('permissions', ['rbac_role.edit']);
        $rules->beforeSave($admin);

        // Without the editor role's view grant, no staff role may see roles any more.
        $dm->saveType($admin, false);
        try {
            $rules->beforeDelete($dm->findType('rbac_role', 'r-editor'));
            $this->fail('deleting the only staff role granting rbac_role.view was allowed');
        } catch (\Preflow\Folio\Extension\Customization\Refused $e) {
            $this->assertSame('No staff role would be left that may edit roles.', $e->getMessage());
        }
    }

    #[RunInSeparateProcess]
    public function test_population_change_of_last_admin_role_refused(): void
    {
        $this->install();
        $admin = $this->adminId();
        $this->as('admin');

        $res = $this->send('POST', '/folio/rbac_role/' . $admin, ['name' => 'admin', 'label' => 'Administrator', 'population' => 'members', 'permissions' => ['*']]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('You would lose the right to edit roles.', (string) $res->getBody());
        $this->assertSame('staff', $this->stored('rbac_role', $admin)?->get('population'));
    }

    #[RunInSeparateProcess]
    public function test_delete_own_granting_role_refused(): void
    {
        $this->install();
        $admin = $this->adminId();
        $this->role('r-keeper', 'staff', 'keeper', ['rbac_role.*']);
        $this->as('admin');

        $res = $this->send('POST', '/folio/rbac_role/' . $admin . '/delete');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNotNull($this->stored('rbac_role', $admin));
        $this->assertStringContainsString('You would lose the right to edit roles.', (string) $this->send('GET', '/folio/rbac_role')->getBody());

        // Another user's granting role may go while the admin role still grants.
        $this->assertSame(302, $this->send('POST', '/folio/rbac_role/r-keeper/delete')->getStatusCode());
        $this->assertNull($this->stored('rbac_role', 'r-keeper'));
    }

    #[RunInSeparateProcess]
    public function test_last_role_edit_grant_cannot_go_without_signed_in_user(): void
    {
        $this->install();
        $this->role('r-editor', 'staff', 'editor', ['page.*']);
        $c = $this->container(); // nobody signed in (as on the CLI): only the staff-wide check applies
        $rules = $c->get(\Preflow\Rbac\Types\RoleRules::class);
        $admin = $c->get(\Preflow\Data\DataManager::class)->findType('rbac_role', $this->adminId());

        try {
            $rules->beforeDelete($admin);
            $this->fail('deleting the only staff role granting rbac_role.edit was allowed');
        } catch (\Preflow\Folio\Extension\Customization\Refused $e) {
            $this->assertSame('No staff role would be left that may edit roles.', $e->getMessage());
        }

        $admin->set('population', 'members');
        try {
            $rules->beforeSave($admin);
            $this->fail('moving the only staff role granting rbac_role.edit to members was allowed');
        } catch (\Preflow\Validation\ValidationException $e) {
            $this->assertSame(['permissions' => ['No staff role would be left that may edit roles.']], $e->errors());
        }

        // Another staff role keeps it: the editor role may go.
        $rules->beforeDelete($c->get(\Preflow\Data\DataManager::class)->findType('rbac_role', 'r-editor'));
    }

    #[RunInSeparateProcess]
    public function test_editor_role_limits_folio_access(): void
    {
        $this->install();
        $this->role('r-editor', 'staff', 'editor', ['folio.dashboard', 'page.*']);
        $this->as('editor');

        $pages = $this->send('GET', '/folio/page');
        $this->assertSame(200, $pages->getStatusCode());
        $body = (string) $pages->getBody();
        $this->assertStringContainsString('<span>Pages</span>', $body);
        $this->assertStringNotContainsString('href="/folio/rbac_role"', $body);
        $this->assertSame(403, $this->send('GET', '/folio/rbac_role')->getStatusCode());
    }
}
