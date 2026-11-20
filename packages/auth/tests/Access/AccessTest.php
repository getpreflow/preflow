<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Access;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\Access\UndeclaredPermission;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\Tests\Fixtures\TestUser;

final class AccessTest extends TestCase
{
    private PermissionRegistry $registry;
    private AuthManager $auth;
    private string $log = '';

    protected function setUp(): void
    {
        $this->registry = new PermissionRegistry();
        $this->registry->declarePattern('page.*');
        foreach (['page.view', 'page.edit', 'page.publish'] as $name) {
            $this->registry->declare($name);
        }
        $this->auth = new AuthManager([], 'session');
        $this->log = (string) tempnam(sys_get_temp_dir(), 'acc');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_restore('error_log');
        @unlink($this->log);
    }

    private function access(bool $debug = false, array $populations = []): Access
    {
        return new Access($this->auth, $this->registry, $debug, $populations);
    }

    public function test_user_is_the_signed_in_user(): void
    {
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function (): void {
            $access = $this->access();
            $this->assertNull($access->user());

            $user = new TestUser();
            $this->auth->setUser($user, 'site');
            $this->assertSame($user, $access->user());
            $this->assertNull((new Access(null, $this->registry, false, []))->user());
        });
    }

    public function test_staff_may_do_everything_by_default(): void
    {
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function (): void {
            $access = $this->access(populations: ['folio' => 'staff']);
            $this->auth->setUser(new TestUser(), 'folio');

            $this->assertTrue($access->can('page.publish'));
        });
    }

    public function test_members_are_denied_by_default(): void
    {
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function (): void {
            $access = $this->access();
            $this->auth->setUser(new TestUser(), 'site'); // no population: members

            $this->assertFalse($access->can('page.publish'));
        });
    }

    public function test_without_a_user_nothing_is_allowed(): void
    {
        $this->assertFalse($this->access(populations: ['folio' => 'staff'])->can('page.edit'));
    }

    public function test_undeclared_permission_throws_in_debug(): void
    {
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function (): void {
            $this->auth->setUser(new TestUser(), 'folio');

            $this->expectException(UndeclaredPermission::class);
            $this->access(debug: true, populations: ['folio' => 'staff'])->can('pgae.edit');
        });
    }

    public function test_undeclared_permission_is_denied_and_logged_once_in_production(): void
    {
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function (): void {
            $access = $this->access(populations: ['folio' => 'staff']);
            $this->auth->setUser(new TestUser(), 'folio');

            $this->assertFalse($access->can('pgae.edit'));
            $this->assertFalse($access->can('pgae.edit'));

            $this->assertSame(1, substr_count((string) file_get_contents($this->log), 'pgae.edit'));
        });
    }

    public function test_the_resolver_is_the_default_until_one_is_set(): void
    {
        $access = $this->access();
        $this->assertInstanceOf(\Preflow\Auth\Access\DefaultResolver::class, $access->resolver());

        $own = new \Preflow\Auth\Access\DefaultResolver();
        $access->setResolver($own);
        $this->assertSame($own, $access->resolver());
    }

    public function test_a_resolver_answers_once_set(): void
    {
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function (): void {
            $access = $this->access(populations: ['folio' => 'staff']);
            $this->auth->setUser(new TestUser(roles: ['editor']), 'folio');
            $access->setResolver(new class implements PermissionResolver {
                public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
                {
                    return $population === 'staff' && $user->hasRole('editor') && $permission === 'page.edit';
                }
            });

            $this->assertTrue($access->can('page.edit'));
            $this->assertFalse($access->can('page.publish'));
        });
    }

    public function test_population_from_config_or_marked_staff_guard(): void
    {
        $access = $this->access(populations: ['site' => 'members']);
        $access->markStaffGuard('admin');
        $access->markStaffGuard('site'); // config wins

        $this->assertSame('staff', $access->populationOf('admin'));
        $this->assertSame('members', $access->populationOf('site'));
        $this->assertSame('members', $access->populationOf('other'));
    }

    public function test_a_declared_population_other_than_staff_is_never_made_staff_by_a_mark(): void
    {
        $access = $this->access(populations: ['employers' => 'employers', 'folio' => 'staff']);
        $access->markStaffGuard('employers'); // config wins: employers stay out of staff

        $this->assertSame('members', $access->populationOf('employers'));
        $this->assertSame('employers', $access->declaredPopulation('employers'));
        $this->assertSame('staff', $access->declaredPopulation('folio'));
        $this->assertNull($access->declaredPopulation('other'), 'none declared');
    }

    public function test_roles_of_a_population_come_from_a_resolver_that_lists_them(): void
    {
        $access = $this->access();
        $this->assertNull($access->roles('staff'), 'the default resolver has no roles');

        $access->setResolver(new class implements \Preflow\Auth\Access\PermissionResolver, \Preflow\Auth\Access\ListsRoles {
            public function allows(\Preflow\Auth\Authenticatable $user, string $population, string $permission, ?object $subject): bool
            {
                return false;
            }

            public function roles(string $population): array
            {
                return $population === 'staff' ? ['admin', 'editor', 'seo'] : [];
            }
        });

        $this->assertSame(['admin', 'editor', 'seo'], $access->roles('staff'));
        $this->assertSame([], $access->roles('members'));
    }

    public function test_population_of_the_signed_in_user(): void
    {
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function (): void {
            $access = $this->access(populations: ['folio' => 'staff']);
            $this->assertNull($access->population(), 'nobody signed in');

            $this->auth->setUser(new TestUser(), 'folio');
            $this->assertSame('staff', $access->population());
            $this->auth->setUser(new TestUser(), 'site');
            $this->assertSame('members', $access->population());
            $this->assertNull((new Access(null, $this->registry, false, []))->population(), 'no auth configured');
        });
    }

    public function test_without_auth_configured_everything_is_allowed(): void
    {
        $this->assertTrue((new Access(null, $this->registry, true, []))->can('anything.at.all'));
    }

    public function test_typo_in_a_family_throws_in_debug(): void
    {
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function (): void {
            $this->auth->setUser(new \Preflow\Auth\Tests\Fixtures\TestUser(), 'folio');
            $this->expectException(\Preflow\Auth\Access\UndeclaredPermission::class);
            $this->access(debug: true, populations: ['folio' => 'staff'])->can('page.pubish');
        });
    }

    public function test_disabled_permission_denied_not_thrown_in_debug(): void
    {
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function (): void {
            $this->registry->declare('members.approve', 'Approve', 'Members');
            $this->registry->disable('members.approve', 'extension members');
            $this->auth->setUser(new TestUser(), 'folio');

            $this->assertFalse($this->access(debug: true, populations: ['folio' => 'staff'])->can('members.approve'));
            $this->assertSame('extension members', $this->registry->disabledReason('members.approve'));
            $this->assertNull($this->registry->disabledReason('page.view'));
        });
    }

    public function test_disabled_logged_once(): void
    {
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function (): void {
            $this->registry->declare('members.approve');
            $this->registry->disable('members.approve', 'extension members');
            $access = $this->access(populations: ['folio' => 'staff']);
            $this->auth->setUser(new TestUser(), 'folio');

            $access->can('members.approve');
            $access->can('members.approve');

            $log = (string) file_get_contents($this->log);
            $this->assertSame(1, substr_count($log, 'permission members.approve belongs to a disabled extension members'));
        });
    }

    public function test_disabled_permission_denied_without_auth(): void
    {
        $this->registry->declare('members.approve');
        $this->registry->disable('members.approve', 'extension members');
        $access = new Access(null, $this->registry, true, []);

        $this->assertFalse($access->can('members.approve'));
        $this->assertFalse($access->can('members.approve'));
        $this->assertSame(1, substr_count((string) file_get_contents($this->log), 'permission members.approve belongs to a disabled extension members'));
    }

    /**
     * A role view (view as role) is answered for its roles in its own population
     * (AllowsRoles), never by allows() and never by a guard: the population 'staff' of a role
     * view does not make it a staff guard's user, and a staff guard of that name changes nothing.
     */
    public function test_a_role_view_is_answered_from_its_roles_grants(): void
    {
        $resolver = new class implements PermissionResolver, \Preflow\Auth\Access\AllowsRoles {
            /** @var list<array{list<string>, string}> */
            public array $asked = [];

            public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
            {
                throw new \LogicException('allows() is never asked for a role view');
            }

            public function allowsRoles(array $roles, string $population, string $permission, ?object $subject): bool
            {
                $this->asked[] = [$roles, $population];
                $patterns = match ($population) {
                    'staff' => in_array('editor', $roles, true) ? ['page.edit', 'page.view'] : [],
                    'members' => in_array('gold', $roles, true) ? ['page.*'] : [],
                };
                foreach ($patterns as $pattern) {
                    if (\Preflow\Auth\Access\Pattern::matches($pattern, $permission)) {
                        return true;
                    }
                }

                return false;
            }
        };
        $access = $this->access(populations: ['staff' => 'staff', 'member' => 'staff']);
        $access->setResolver($resolver);
        $ask = static fn (): array => [$access->can('page.edit'), $access->can('page.publish'), $access->canAny('page.edit'), $access->population()];

        $staff = \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest()->asRole(new \Preflow\Core\Context\RoleView('staff', ['editor'])), $ask);
        $member = \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest()->asRole(new \Preflow\Core\Context\RoleView('member', ['gold'])), $ask);
        $nothing = \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest()->asRole(new \Preflow\Core\Context\RoleView('member', ['editor'])), $ask);

        $this->assertSame([true, false, true, 'staff'], $staff);
        $this->assertSame([true, true, true, 'members'], $member, "the 'member' population counts as members, though a guard named 'member' is staff");
        $this->assertSame([false, false, false, 'members'], $nothing, 'a role is read in its own population');
        $this->assertContains([['editor'], 'staff'], $resolver->asked);
        $this->assertContains([['gold'], 'members'], $resolver->asked);
        $this->assertSame('staff', Access::populationOfRoleView(new \Preflow\Core\Context\RoleView('staff', [])));
        $this->assertSame('members', Access::populationOfRoleView(new \Preflow\Core\Context\RoleView('members', [])));
    }

    /**
     * The subject reaches the resolver: one that lets authors see only public posts (and their own,
     * which a role view has none of) gives an author role view no access to anybody's private
     * post, through can() and canAny() alike.
     */
    public function test_a_role_view_question_carries_its_subject(): void
    {
        $resolver = new class implements PermissionResolver, \Preflow\Auth\Access\AllowsRoles {
            /** @var list<?object> */
            public array $subjects = [];

            public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
            {
                return $permission === 'page.view' && $user->hasRole('author') && ($subject?->public === true || $subject?->owner === $user->getAuthId());
            }

            public function allowsRoles(array $roles, string $population, string $permission, ?object $subject): bool
            {
                $this->subjects[] = $subject;

                return $permission === 'page.view' && in_array('author', $roles, true) && $subject?->public === true;
            }
        };
        $access = $this->access(populations: ['members' => 'members']);
        $access->setResolver($resolver);
        $public = (object) ['public' => true, 'owner' => 'm-2'];
        $private = (object) ['public' => false, 'owner' => 'm-2'];

        $answers = \Preflow\Core\Context\Contexts::run(
            \Preflow\Testing\ContextFactory::forTest()->asRole(new \Preflow\Core\Context\RoleView('member', ['author'])),
            static fn (): array => [$access->can('page.view', $public), $access->can('page.view', $private), $access->canAny('page.view', $public), $access->canAny('page.view', $private)],
        );

        $this->assertSame([true, false, true, false], $answers);
        $this->assertSame([$public, $private, $public, $private], $resolver->subjects, 'every question carried its subject');
    }

    /** A resolver that cannot say what roles grant (auth's default: staff may do everything) grants a role view nothing. */
    public function test_a_role_view_gets_nothing_from_a_resolver_without_grants(): void
    {
        $access = $this->access(populations: ['staff' => 'staff']);

        $answers = \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest()->asRole(new \Preflow\Core\Context\RoleView('staff', ['admin'])), static fn (): array => [$access->can('page.edit'), $access->canAny('page.edit')]);

        $this->assertSame([false, false], $answers);
        // The same staff guard's real user may do everything.
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function () use ($access): void {
            @$this->auth->setUser(new TestUser(roles: ['admin']), 'staff');
            $this->assertTrue($access->can('page.edit'));
        });
    }
}
