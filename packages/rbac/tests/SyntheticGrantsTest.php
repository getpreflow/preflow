<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\GrantsForRoles;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Rbac\RbacResolver;
use Preflow\Rbac\RbacServiceProvider;
use Preflow\Rbac\RoleStore;

/**
 * What a role view (view as role) may do comes from its roles alone: RbacResolver::grantsFor()
 * reads the stored role definitions and nobody's account, so the same roles always give the
 * same grants, whoever asks.
 */
final class SyntheticGrantsTest extends TestCase
{
    /** @param list<array{string, string, mixed}> $roles population, name, permissions */
    private static function resolver(array $roles, bool $installed = true): RbacResolver
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

    public function test_grants_for_roles_is_pure(): void
    {
        $resolver = self::resolver([
            ['staff', 'admin', ['*']],
            ['staff', 'editor', ['pages.edit', 'pages.publish']],
            ['staff', 'writer', ['pages.edit', 'assets.*']],
            ['members', 'gold', ['members.access', 'shop.discount']],
            ['members', 'editor', ['forum.post']],
        ]);

        $this->assertInstanceOf(GrantsForRoles::class, $resolver);
        $this->assertSame(['pages.edit', 'pages.publish'], $resolver->grantsFor(['editor'], 'staff'));
        $this->assertSame(['pages.edit', 'pages.publish', 'assets.*'], $resolver->grantsFor(['editor', 'writer'], 'staff'), 'each pattern once, in role order');
        $this->assertSame($resolver->grantsFor(['editor', 'writer'], 'staff'), $resolver->grantsFor(['editor', 'writer'], 'staff'), 'the same roles give the same grants');
        $this->assertSame(['*'], $resolver->grantsFor(['admin'], 'staff'));
        // A role name is read in its own population only.
        $this->assertSame(['forum.post'], $resolver->grantsFor(['editor'], 'members'));
        $this->assertSame(['members.access', 'shop.discount'], $resolver->grantsFor(['gold'], 'members'));
        $this->assertSame([], $resolver->grantsFor(['gold'], 'staff'), 'a member role grants no staff permission');
        // Unknown and malformed roles grant nothing; no roles grant nothing.
        $this->assertSame([], $resolver->grantsFor(['owner'], 'staff'));
        $this->assertSame([], $resolver->grantsFor([], 'staff'));
        $this->assertSame(['pages.edit', 'pages.publish'], $resolver->grantsFor([' editor ', 'editor', 'nobody'], 'staff'));
    }

    /**
     * Until rbac is installed, staff may do everything (allows() and patterns() say so, so turning
     * rbac on never locks the admin out); a role view has no stored roles to read then and gets
     * nothing: it never gets more than its roles' own grants.
     */
    public function test_a_role_view_gets_nothing_before_rbac_is_installed(): void
    {
        $resolver = self::resolver([], installed: false);

        $this->assertSame([], $resolver->grantsFor(['admin'], 'staff'));
        $this->assertSame([], $resolver->grantsFor(['member'], 'members'));
    }

    /** rbac declares the permission to view the site as a role; the staff role "admin" holds it from the start. */
    public function test_site_view_as_is_declared_and_admin_holds_it(): void
    {
        $registry = new PermissionRegistry();
        RbacServiceProvider::declarePermissions($registry);

        $this->assertTrue($registry->isDeclared('site.view_as'));
        $this->assertSame('View the site as a role', $registry->all()['site.view_as']['label']);

        $typeRegistry = new TypeRegistry(dirname(__DIR__) . '/models');
        $dm = new DataManager(drivers: ['default' => new SqliteDriver(new \PDO('sqlite::memory:'))], typeRegistry: $typeRegistry);
        $store = new RoleStore($dm);
        $store->install();
        $store->seed(['staff' => ['editor' => ['pages.edit']]], $registry);
        $resolver = new RbacResolver($store);

        $this->assertContains('*', $resolver->grantsFor(['admin'], 'staff'), 'the seeded admin role grants everything, site.view_as included');
        $this->assertNotContains('site.view_as', $resolver->grantsFor(['editor'], 'staff'));
    }
}
