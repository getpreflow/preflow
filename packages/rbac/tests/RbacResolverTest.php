<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Rbac\RbacResolver;
use Preflow\Rbac\RoleStore;

final class RbacResolverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rbac_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /**
     * Roles on SQLite; not installed: no rbac_role table (so no roles either).
     *
     * @param list<array{string, string, mixed}> $roles population, name, permissions
     */
    private function resolver(array $roles, bool $installed = true): RbacResolver
    {
        $registry = new TypeRegistry(dirname(__DIR__) . '/models');
        $dm = new DataManager(drivers: ['default' => new SqliteDriver(new \PDO('sqlite::memory:'))], typeRegistry: $registry);
        $store = new RoleStore($dm);
        if ($installed) {
            $store->install();
        }
        foreach ($roles as $i => [$population, $name, $permissions]) {
            $dm->saveType(DynamicRecord::fromArray($registry->get('rbac_role'), [
                'uuid' => 'r' . $i, 'name' => $name, 'label' => ucfirst($name),
                'population' => $population, 'permissions' => $permissions,
            ]), validate: false);
        }

        return new RbacResolver(new RoleStore($dm));
    }

    public function test_staff_role_pattern_grants(): void
    {
        $resolver = $this->resolver([['staff', 'editor', ['page.*', 'asset.view']]]);
        $editor = new TestUser(roles: ['editor']);

        $this->assertTrue($resolver->allows($editor, 'staff', 'page.edit', null));
        $this->assertTrue($resolver->allows($editor, 'staff', 'asset.view', null));
        $this->assertFalse($resolver->allows($editor, 'staff', 'rbac_role.edit', null));
    }

    public function test_it_lists_the_roles_defined_for_a_population(): void
    {
        $resolver = $this->resolver([['staff', 'admin', ['*']], ['staff', 'seo', ['page.*']], ['members', 'member', ['members.access']]]);

        $this->assertInstanceOf(\Preflow\Auth\Access\ListsRoles::class, $resolver);
        $this->assertSame(['admin', 'seo'], $resolver->roles('staff'));
        $this->assertSame(['member'], $resolver->roles('members'));
    }

    public function test_patterns_are_the_users_roles_patterns_in_their_population(): void
    {
        $resolver = $this->resolver([
            ['staff', 'editor', ['page.*', 'asset.view']],
            ['staff', 'helper', ['page.view', 'codes.revoke']],
            ['members', 'editor', ['tools.*']],
        ]);

        $this->assertSame(['page.*', 'asset.view', 'page.view', 'codes.revoke'], $resolver->patterns(new TestUser(roles: ['editor', 'helper', 'ghost']), 'staff'));
        $this->assertSame(['tools.*'], $resolver->patterns(new TestUser(roles: ['editor']), 'members'));
        $this->assertSame([], $resolver->patterns(new TestUser(roles: []), 'staff'));
    }

    public function test_patterns_before_install_staff_hold_everything(): void
    {
        $resolver = $this->resolver([], installed: false);

        $this->logged(function () use ($resolver): void {
            $this->assertSame(['*'], $resolver->patterns(new TestUser(roles: ['editor']), 'staff'));
            $this->assertSame([], $resolver->patterns(new TestUser(roles: ['member']), 'members'));
            $this->assertSame([], $resolver->roles('staff'));
        }, 'not installed');
    }

    public function test_member_role_pattern_grants_only_members_population(): void
    {
        $resolver = $this->resolver([
            ['staff', 'admin', ['*']],
            ['members', 'member', ['tools.*']],
        ]);
        $member = new TestUser(roles: ['member']);

        $this->assertTrue($resolver->allows($member, 'members', 'tools.use', null));
        $this->assertFalse($resolver->allows($member, 'staff', 'tools.use', null));
        // A staff role name held by a member grants nothing in the members population.
        $this->assertFalse($resolver->allows(new TestUser(roles: ['admin']), 'members', 'tools.use', null));
    }

    public function test_unknown_role_grants_nothing(): void
    {
        $resolver = $this->resolver([['staff', 'editor', ['page.*']]]);

        $this->assertFalse($resolver->allows(new TestUser(roles: ['ghost']), 'staff', 'page.edit', null));
        $this->assertFalse($resolver->allows(new TestUser(roles: []), 'staff', 'page.edit', null));
        // Members with no roles defined get nothing.
        $this->assertFalse($resolver->allows(new TestUser(roles: ['member']), 'members', 'tools.use', null));
    }

    public function test_roles_as_json_string_resolve(): void
    {
        // As SQL returns them: permissions and the user's roles are JSON strings.
        $resolver = $this->resolver([['members', 'member', '["tools.*"]']]);

        $this->assertTrue($resolver->allows(new TestUser(roles: ['["member"]']), 'members', 'tools.use', null));
        $this->assertFalse($resolver->allows(new TestUser(roles: ['["member"]']), 'members', 'page.edit', null));
    }

    public function test_not_installed_staff_may_do_everything(): void
    {
        // The provider runs, but `php preflow rbac:install` has not made the table here yet.
        $resolver = $this->resolver([], installed: false);
        $lines = $this->logged(function () use ($resolver): void {
            $this->assertTrue($resolver->allows(new TestUser(roles: ['admin']), 'staff', 'rbac_role.edit', null));
            $this->assertTrue($resolver->allows(new TestUser(roles: []), 'staff', 'page.edit', null));
            $this->assertFalse($resolver->allows(new TestUser(roles: []), 'members', 'tools.use', null));
        }, 'not installed');

        $this->assertCount(1, $lines, 'logged once per request');
        $this->assertStringContainsString('run php preflow rbac:install', $lines[0]);
    }

    public function test_install_in_the_same_process_is_seen(): void
    {
        $registry = new TypeRegistry(dirname(__DIR__) . '/models');
        $dm = new DataManager(drivers: ['default' => new SqliteDriver(new \PDO('sqlite::memory:'))], typeRegistry: $registry);
        $store = new RoleStore($dm);
        $resolver = new RbacResolver($store);
        $user = new TestUser(roles: []);

        $this->logged(function () use ($resolver, $user): void {
            $this->assertTrue($resolver->allows($user, 'staff', 'page.edit', null), 'before install');
        }, 'not installed');
        $store->install();
        $this->logged(function () use ($resolver, $user): void {
            $this->assertFalse($resolver->allows($user, 'staff', 'page.edit', null), 'after install');
        }, 'no staff roles defined');
    }

    public function test_after_install_empty_staff_roles_grant_nothing(): void
    {
        $resolver = $this->resolver([['members', 'member', ['tools.*']]]);
        $lines = $this->logged(function () use ($resolver): void {
            $this->assertFalse($resolver->allows(new TestUser(roles: ['admin']), 'staff', 'rbac_role.edit', null));
            $this->assertFalse($resolver->allows(new TestUser(roles: []), 'staff', 'page.edit', null));
            $this->assertTrue($resolver->allows(new TestUser(roles: ['member']), 'members', 'tools.use', null));
        }, 'no staff roles defined');

        $this->assertCount(1, $lines, 'logged once per request');
        $this->assertStringContainsString('run php preflow rbac:install, or create one with php preflow rbac:role', $lines[0]);
    }

    /** @return list<string> the error_log lines containing $needle written while $run ran */
    private function logged(\Closure $run, string $needle): array
    {
        $log = $this->dir . '/error.log';
        $previous = ini_set('error_log', $log);
        try {
            $run();
        } finally {
            ini_set('error_log', (string) $previous);
        }

        return array_values(array_filter(explode("\n", (string) @file_get_contents($log)), fn ($l) => str_contains($l, $needle)));
    }
}
