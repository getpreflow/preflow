<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Console\ExtensionsApplyCommand;
use Preflow\Folio\Extension\ExtensionState;
use Preflow\Rbac\RoleStore;

/** Extension::install() (from folio:extensions:apply) and RoleStore::ensure(). */
final class InstallTest extends TestCase
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

    /** @return array<string, array{label: mixed, permissions: mixed}> "population/name" => role */
    private function byName(): array
    {
        $out = [];
        foreach ($this->roles() as $role) {
            $out[$role->get('population') . '/' . $role->get('name')] = ['label' => $role->get('label'), 'permissions' => $role->get('permissions')];
        }
        ksort($out);

        return $out;
    }

    private function apply(): string
    {
        ob_start();
        try {
            (new ExtensionsApplyCommand(fn () => $this->session ??= $this->app()))->execute([]);
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }

    #[RunInSeparateProcess]
    public function test_install_creates_admin_and_imports_config_roles(): void
    {
        $this->write('config/roles.php', "<?php\nreturn " . var_export([
            'staff' => ['editor' => ['page.*'], 'content_lead' => 'page.*, assets.*'],
            'members' => ['member' => ['tools.*']],
        ], true) . ";\n");

        $this->assertStringContainsString('installed rbac 1.0.0', $this->apply());

        $this->assertSame([
            'members/member' => ['label' => 'Member', 'permissions' => ['tools.*']],
            'staff/admin' => ['label' => 'Administrator', 'permissions' => ['*']],
            'staff/content_lead' => ['label' => 'Content lead', 'permissions' => ['page.*', 'assets.*']],
            'staff/editor' => ['label' => 'Editor', 'permissions' => ['page.*']],
        ], $this->byName());
        $this->assertSame('1.0.0', $this->container()->get(ExtensionState::class)->installedVersion('rbac'));
    }

    #[RunInSeparateProcess]
    public function test_install_is_idempotent(): void
    {
        $this->write('config/roles.php', "<?php\nreturn ['staff' => ['editor' => ['page.*']]];\n");
        $this->install();
        $once = $this->byName();

        // A role changed in Folio is never overwritten; other roles stay.
        $this->role('r-x', 'staff', 'helper', ['page.view']);
        foreach ($this->roles() as $role) {
            if ($role->get('name') === 'editor') {
                $role->set('permissions', ['page.view']);
                $this->container()->get(DataManager::class)->saveType($role, false);
            }
        }
        $this->install();
        $this->install();

        $twice = $this->byName();
        $this->assertCount(3, $twice);
        $this->assertSame($once['staff/admin'], $twice['staff/admin']);
        $this->assertSame(['page.view'], $twice['staff/editor']['permissions']);
        $this->assertSame(['page.view'], $twice['staff/helper']['permissions']);
    }

    #[RunInSeparateProcess]
    public function test_install_without_admin_when_a_staff_role_exists(): void
    {
        $this->role('r-boss', 'staff', 'boss', ['*']);
        $this->install();

        $this->assertSame(['staff/boss'], array_keys($this->byName()));
    }

    #[RunInSeparateProcess]
    public function test_ensure_creates_missing_role_only(): void
    {
        $store = new RoleStore($this->container()->get(DataManager::class));
        $store->ensure('members', 'member', 'Member', ['tools.*']);
        $store->ensure('members', 'member', 'Other', ['page.*']);

        $this->assertSame(['member' => ['tools.*']], $store->rolesFor('members'));
        $this->assertSame(['members/member' => ['label' => 'Member', 'permissions' => ['tools.*']]], $this->byName());
    }
}
