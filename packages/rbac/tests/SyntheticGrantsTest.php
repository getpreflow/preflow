<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\AllowsRoles;
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

        $this->assertInstanceOf(AllowsRoles::class, $resolver);
        // The question a role view asks: its roles, in its own population.
        $this->assertTrue($resolver->allowsRoles(['editor'], 'staff', 'pages.edit', null));
        $this->assertFalse($resolver->allowsRoles(['editor'], 'staff', 'site.view_as', null));
        $this->assertTrue($resolver->allowsRoles(['writer'], 'staff', 'assets.upload', null), 'patterns match as allows() matches them');
        $this->assertTrue($resolver->allowsRoles(['admin'], 'staff', 'site.view_as', null));
        $this->assertTrue($resolver->allowsRoles(['editor'], 'members', 'forum.post', null));
        $this->assertFalse($resolver->allowsRoles(['editor'], 'members', 'pages.edit', null), 'a role name is read in its own population');
        $this->assertFalse($resolver->allowsRoles(['gold'], 'staff', 'members.access', null));
        $this->assertFalse($resolver->allowsRoles([], 'staff', 'pages.edit', null));
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

    /** rolesWithin(): a role's patterns all covered by the user's own (view as role never above one's rights). */
    public function test_roles_within_compares_the_roles_patterns_with_the_users(): void
    {
        $resolver = self::resolver([
            ['staff', 'admin', ['*']],
            ['staff', 'editor', ['pages.edit', 'pages.publish']],
            ['staff', 'writer', ['pages.edit', 'assets.*']],
            ['staff', 'assets', ['assets.upload']],
        ]);
        $user = static fn (array $roles): \Preflow\Auth\Authenticatable => new class ($roles) implements \Preflow\Auth\Authenticatable {
            use \Preflow\Auth\AuthenticatableTrait;

            public function __construct(private readonly array $roles) {}

            public function getAuthId(): string
            {
                return 'u';
            }

            public function getRoles(): array
            {
                return $this->roles;
            }
        };

        $this->assertInstanceOf(\Preflow\Auth\Access\ComparesRoles::class, $resolver);
        $this->assertTrue($resolver->rolesWithin(['editor', 'writer'], 'staff', $user(['admin'])), '"*" covers everything');
        $this->assertFalse($resolver->rolesWithin(['admin'], 'staff', $user(['editor'])), 'never as an admin');
        $this->assertTrue($resolver->rolesWithin(['assets'], 'staff', $user(['writer'])), '"assets.*" covers "assets.upload"');
        $this->assertFalse($resolver->rolesWithin(['writer'], 'staff', $user(['editor'])), '"assets.*" is not the editor\'s');
        $this->assertTrue($resolver->rolesWithin(['editor'], 'staff', $user(['editor'])));
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
        $this->assertFalse($resolver->allowsRoles(['admin'], 'staff', 'pages.edit', null));
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
