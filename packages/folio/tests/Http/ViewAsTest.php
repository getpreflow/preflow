<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\AllowsRoles;
use Preflow\Auth\Access\ComparesRoles;
use Preflow\Auth\Access\ListsRoles;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RoleView;
use Preflow\Folio\Http\ViewAs;
use Preflow\Testing\ContextFactory;

/**
 * The "View as" value of a whole-page preview: only the one role asked for is checked (never every
 * role, as the list of choices does), and only a listed role the editor may view as is accepted.
 */
final class ViewAsTest extends TestCase
{
    public function test_a_value_is_checked_for_its_own_role_only(): void
    {
        $resolver = new class implements PermissionResolver, ListsRoles, AllowsRoles, ComparesRoles {
            /** @var list<list<string>> */
            public array $compared = [];

            public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
            {
                return true;
            }

            public function roles(string $population): array
            {
                return $population === 'staff' ? ['admin', 'editor', 'writer'] : ['gold'];
            }

            public function allowsRoles(array $roles, string $population, string $permission, ?object $subject): bool
            {
                return true;
            }

            public function rolesWithin(array $roles, string $population, Authenticatable $user): bool
            {
                $this->compared[] = $roles;

                return $roles !== ['admin'];
            }
        };
        $registry = new PermissionRegistry();
        $registry->declare(ViewAs::PERMISSION);
        $auth = new AuthManager([], 'staff');
        $access = new Access($auth, $registry, true, ['staff' => 'staff']);
        $access->setResolver($resolver);
        $viewAs = new ViewAs($access);

        $answers = Contexts::run(ContextFactory::forTest(), static function () use ($auth, $viewAs): array {
            @$auth->setUser(new class implements Authenticatable {
                use \Preflow\Auth\AuthenticatableTrait;

                public function getAuthId(): string
                {
                    return 'editor-1';
                }

                public function getRoles(): array
                {
                    return ['editor'];
                }
            }, 'staff');
            $refused = static function (string $value) use ($viewAs): bool {
                try {
                    $viewAs->roleView($value);

                    return false;
                } catch (\DomainException) {
                    return true;
                }
            };

            return [
                $viewAs->roleView('staff:writer'),
                $viewAs->roleView('member:gold'),
                $viewAs->roleView(''),
                $refused('staff:admin'),
                $refused('staff:owner'),
                $refused('guest:gold'),
                $refused('staff:'),
                $refused('staff:writer,admin'),
            ];
        });

        $this->assertEquals(new RoleView('staff', ['writer']), $answers[0]);
        $this->assertEquals(new RoleView('member', ['gold']), $answers[1]);
        $this->assertNull($answers[2], 'the anonymous visitor');
        $this->assertSame([true, true, true, true, true], array_slice($answers, 3), 'a role with more rights, an unknown role, population or empty role, and a list');
        $this->assertSame([['writer'], ['admin']], $resolver->compared, 'one comparison per value asked, for its own role only');
    }
}
