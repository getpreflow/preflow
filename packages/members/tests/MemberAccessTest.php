<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\DefaultResolver;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\ArrayUser;
use Preflow\Auth\Authenticatable;
use Preflow\Members\Access\MemberAccess;
use Preflow\Members\MembersConfig;
use Preflow\Members\Tests\Support\MembersScaffold;

/** Without rbac, an admitted member (role "member") may use the members area, and nothing more. */
final class MemberAccessTest extends TestCase
{
    use MembersScaffold;

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** @param list<string> $roles */
    private static function user(array $roles): Authenticatable
    {
        return new ArrayUser('m1', 'ada@example.com', '', $roles);
    }

    public function test_an_admitted_member_may_use_the_members_area(): void
    {
        $access = new MemberAccess(new DefaultResolver());

        $this->assertTrue($access->allows(self::user(['member']), 'members', MembersConfig::PERMISSION, null));
        $this->assertTrue($access->allows(self::user(['vip', 'member']), 'members', MembersConfig::PERMISSION, null));
    }

    public function test_pending_rejected_and_roleless_members_may_not(): void
    {
        $access = new MemberAccess(new DefaultResolver());

        foreach ([['pending'], ['rejected'], [], ['members']] as $roles) {
            $this->assertFalse($access->allows(self::user($roles), 'members', MembersConfig::PERMISSION, null), implode(',', $roles));
        }
    }

    public function test_a_member_gets_nothing_else(): void
    {
        $access = new MemberAccess(new DefaultResolver());

        $this->assertFalse($access->allows(self::user(['member']), 'members', MembersConfig::DECIDE, null));
        $this->assertFalse($access->allows(self::user(['member']), 'members', 'page.edit', null));
    }

    public function test_everyone_else_is_the_resolver_before_it(): void
    {
        $access = new MemberAccess(new DefaultResolver());

        $this->assertTrue($access->allows(self::user([]), 'staff', 'page.edit', null), 'staff may do everything, as without members');
    }

    public function test_the_provider_installs_it_over_the_default_resolver(): void
    {
        $this->scaffold();

        $access = $this->app()->container()->get(Access::class);

        $this->assertInstanceOf(MemberAccess::class, $access->resolver());
        $this->assertTrue($access->registry()->isDeclared(MembersConfig::PERMISSION));
    }

    public function test_a_resolver_a_package_set_stays(): void
    {
        $this->scaffold();
        $own = new class implements PermissionResolver {
            public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
            {
                return false;
            }
        };
        $app = \Preflow\Core\Application::create($this->dir);
        $app->registerProvider(new class($own) extends \Preflow\Core\Container\ServiceProvider {
            public function __construct(private readonly PermissionResolver $own) {}

            public function register(\Preflow\Core\Container\Container $container): void
            {
            }

            public function boot(\Preflow\Core\Container\Container $container): void
            {
                $container->get(Access::class)->setResolver($this->own); // as preflow/roles does
            }
        });
        $app->boot();

        $this->assertSame($own, $app->container()->get(Access::class)->resolver());
    }
}
