<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\AllowsRoles;
use Preflow\Auth\Access\ListsRoles;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Context\Viewer;
use Preflow\Folio\Http\ViewAs;
use Preflow\Testing\ContextFactory;

/**
 * The "View as" value of a whole-page preview: any role the resolver lists is accepted, also one
 * with more rights than the editor's own (owner ruling, 0.81: roles are not compared), and nothing
 * else is; without site.view_as nothing is.
 */
final class ViewAsTest extends TestCase
{
    public function test_any_listed_role_is_accepted_and_offered(): void
    {
        $resolver = new class implements PermissionResolver, ListsRoles, AllowsRoles {
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
        };
        $registry = new PermissionRegistry();
        $registry->declare(ViewAs::PERMISSION);
        $auth = new AuthManager([], 'staff');
        $access = new Access($auth, $registry, true, ['staff' => 'staff']);
        $access->setResolver($resolver);
        $viewAs = new ViewAs($access);

        $answers = Contexts::run(ContextFactory::forTest(), static function () use ($auth, $viewAs): array {
            Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->as(new Viewer(['staff' => new class implements Authenticatable {
                use \Preflow\Auth\AuthenticatableTrait;

                public function getAuthId(): string
                {
                    return 'editor-1';
                }

                public function getRoles(): array
                {
                    return ['editor'];
                }
            }], 'staff')));
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
                $viewAs->roleView('staff:admin'),
                $viewAs->options(),
                $refused('staff:owner'),
                $refused('guest:gold'),
                $refused('staff:'),
                $refused('staff:writer,admin'),
            ];
        });

        $this->assertEquals(new RoleView('staff', ['writer']), $answers[0]);
        $this->assertEquals(new RoleView('member', ['gold']), $answers[1]);
        $this->assertNull($answers[2], 'the anonymous visitor');
        $this->assertEquals(new RoleView('staff', ['admin']), $answers[3], 'a role with more rights than the editor\'s own');
        $this->assertSame(['', 'member:gold', 'staff:admin', 'staff:editor', 'staff:writer'], array_column($answers[4], 'value'), 'every role is offered');
        $this->assertSame([true, true, true, true], array_slice($answers, 5), 'an unknown role, population or empty role, and a list');

        // Without site.view_as nothing is accepted.
        $resolver2 = new class implements PermissionResolver, ListsRoles {
            public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
            {
                return $permission !== ViewAs::PERMISSION;
            }

            public function roles(string $population): array
            {
                return ['gold'];
            }
        };
        $access->setResolver($resolver2);
        $refusedWithout = Contexts::run(ContextFactory::forTest(), static function () use ($auth, $viewAs): bool {
            Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->as(new Viewer(['staff' => new class implements Authenticatable {
                use \Preflow\Auth\AuthenticatableTrait;

                public function getAuthId(): string
                {
                    return 'editor-1';
                }

                public function getRoles(): array
                {
                    return ['editor'];
                }
            }], 'staff')));
            try {
                $viewAs->roleView('member:gold');

                return false;
            } catch (\DomainException) {
                return true;
            }
        });
        $this->assertTrue($refusedWithout);
    }
}
