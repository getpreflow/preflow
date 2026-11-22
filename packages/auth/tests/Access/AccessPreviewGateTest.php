<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Access;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\AccessPreviewGate;
use Preflow\Auth\Access\AllowsRoles;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Preview\PreviewClaims;
use Preflow\Core\Context\Preview\PreviewToken;
use Preflow\Core\Context\Preview\PreviewTokenRejected;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Context\Viewer;
use Preflow\Testing\ContextFactory;

/**
 * T7 of the preview token's threat model: what the issuer was checked for is checked again, through
 * Access, at every use of the token.
 */
final class AccessPreviewGateTest extends TestCase
{
    private const KEY = 'access-preview-gate-test-key-0123456789';

    private const SESSION = 'editor-session-id-0001';

    /** @var array<string, list<string>> role => the permissions it grants (staff) */
    private array $grants = ['editor' => ['page.edit', 'page.view', 'site.view_as'], 'viewer' => ['page.view', 'site.view_as']];

    private AuthManager $auth;

    private Access $access;

    protected function setUp(): void
    {
        Contexts::reset();
        $registry = new PermissionRegistry();
        foreach (['page.edit', 'page.view', 'site.view_as'] as $name) {
            $registry->declare($name);
        }
        $this->auth = new AuthManager([], 'staff');
        $this->access = new Access($this->auth, $registry, true, ['staff' => 'staff', 'web' => 'members']);
        $grants = &$this->grants;
        $this->access->setResolver(new class ($grants) implements PermissionResolver, AllowsRoles {
            /** @param array<string, list<string>> $grants */
            public function __construct(private array &$grants) {}

            public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
            {
                return $this->allowsRoles($user->getRoles(), $population, $permission, $subject);
            }

            public function allowsRoles(array $roles, string $population, string $permission, ?object $subject): bool
            {
                foreach ($roles as $role) {
                    if (in_array($permission, $this->grants[$role] ?? [], true)) {
                        return true;
                    }
                }

                return false;
            }
        });
    }

    protected function tearDown(): void
    {
        Contexts::reset();
    }

    /** A permission taken from the editor's role after the token was issued: the next use is refused. */
    public function test_revoked_permission_rejects_the_token(): void
    {
        $token = $this->issue(['page.edit']);
        $this->assertSame('ed', $this->admit($token)->issuer, 'admitted while the editor may edit');

        $this->grants['editor'] = ['page.view', 'site.view_as']; // a role edited in the admin, or with staff:roles

        $this->assertRejected('permission', fn () => $this->admit($token));
    }

    /**
     * Owner ruling (0.81): whoever holds site.view_as may view as any role, also one with more
     * rights than their own. A viewer (no page.edit) keeps viewing as an editor; only the
     * permissions the token requires are checked again.
     */
    public function test_a_role_view_with_more_rights_than_the_editors_own_is_admitted(): void
    {
        $role = new RoleView('staff', ['editor']);
        $token = $this->issue(['page.view', 'site.view_as'], $role);

        $this->assertEquals($role->by('staff'), $this->admit($token, roles: ['viewer'])->role, 'a viewer views as an editor');
    }

    /**
     * I2: a role view's token requires the view-as permission, so taking it away ends the role view
     * at the next request, even though the editor may still view as the role by their roles.
     */
    public function test_revoking_view_as_ends_a_role_view_token(): void
    {
        $role = new RoleView('member', ['gold']);
        $token = $this->issue(['page.view', 'site.view_as'], $role);
        $this->assertEquals($role->by('staff'), $this->admit($token)->role);

        $this->grants['editor'] = ['page.edit', 'page.view']; // site.view_as revoked

        $this->assertRejected('permission', fn () => $this->admit($token));

        // And no role-view token is issued without it.
        $this->expectException(\InvalidArgumentException::class);
        $this->issue(['page.view'], $role);
    }

    /**
     * M7: an editor also signed in as a member, whose member guard comes first on public pages, is
     * admitted: the identity is compared and the permissions asked on the staff guard it was issued with.
     */
    public function test_a_two_guard_editor_is_admitted_on_a_public_path(): void
    {
        $token = $this->issue(['page.edit']);

        $claims = Contexts::run(ContextFactory::forTest(), function () use ($token): PreviewClaims {
            Contexts::narrow(static fn ($ctx) => $ctx->as(AuthManager::viewerOf(['web' => new TestUser('m-7'), 'staff' => new TestUser('ed', roles: ['editor'])], 'web')));
            $this->assertSame('m-7', $this->access->user()?->getAuthId(), 'the member is the primary user here');
            $this->assertFalse($this->access->can('page.edit'), 'who may not edit');

            return (new PreviewToken(self::KEY))->admit($token, self::SESSION, Contexts::current(), new AccessPreviewGate($this->access));
        });

        $this->assertSame(['ed', 'staff'], [$claims->issuer, $claims->issuerGuard]);
    }

    /** It answers for the request's real viewer only: nobody and a role view get nothing, nor does an empty check. */
    public function test_the_gate_fails_closed(): void
    {
        $gate = new AccessPreviewGate($this->access);
        $role = new RoleView('staff', ['viewer']);

        $answers = Contexts::run(ContextFactory::forTest(), function () use ($gate, $role): array {
            $anonymous = Contexts::current();
            Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->as(new Viewer(['staff' => new TestUser('ed', roles: ['editor'])], 'staff')));
            $editor = Contexts::current();

            return [
                'editor' => $gate->allows($editor, ['page.edit']),
                'nothing to check' => $gate->allows($editor, []),
                'every one held' => $gate->allows($editor, ['page.edit', 'page.view']),
                'anonymous' => $gate->allows($anonymous, ['page.view']),
                'a role view' => $gate->allows(ContextFactory::forTest()->asRole(new RoleView('staff', ['editor'])), ['page.view']),
            ];
        });

        $this->assertSame([
            'editor' => true, 'nothing to check' => false, 'every one held' => true,
            'anonymous' => false, 'a role view' => false,
        ], $answers);

        // Asked from inside a role view's frame: nothing.
        $inside = Contexts::run(ContextFactory::forTest()->asRole($role), function () use ($gate): bool {
            $editor = ContextFactory::forTest()->as(\Preflow\Auth\AuthManager::viewerOf(['staff' => new TestUser('ed', roles: ['editor'])], 'staff'));

            return $gate->allows($editor, ['page.view']);
        });
        $this->assertFalse($inside);

        // Outside every render, it opens a frame of its own and leaves none behind.
        $editor = ContextFactory::forTest()->as(\Preflow\Auth\AuthManager::viewerOf(['staff' => new TestUser('ed', roles: ['viewer'])], 'staff'));
        $this->assertSame([true, false], [$gate->allows($editor, ['page.view']), $gate->allows($editor, ['page.edit'])]);
        $this->assertSame(0, Contexts::depth());
    }

    // --- helpers -------------------------------------------------------------------------------

    /** @param list<string> $requires */
    private function issue(array $requires, ?RoleView $role = null): string
    {
        return Contexts::run(ContextFactory::forTest(), function () use ($requires, $role): string {
            Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->as(new Viewer(['staff' => new TestUser('ed', roles: ['editor'])], 'staff')));
            $preview = ContextFactory::forTest()->asVisitor()->workingCopies()->because(Purpose::Preview);

            return (new PreviewToken(self::KEY))->issue($role === null ? $preview : $preview->asRole($role), self::SESSION, 'staff', Contexts::current()->viewer->primary(), $requires);
        });
    }

    /** A follow-up request of the editor (holding $roles) admitted through the auth gate. @param list<string> $roles */
    private function admit(string $token, array $roles = ['editor']): PreviewClaims
    {
        return Contexts::run(ContextFactory::forTest(), function () use ($token, $roles): PreviewClaims {
            Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->as(new Viewer(['staff' => new TestUser('ed', roles: $roles)], 'staff')));

            return (new PreviewToken(self::KEY))->admit($token, self::SESSION, Contexts::current(), new AccessPreviewGate($this->access));
        });
    }

    private function assertRejected(string $reason, \Closure $admit, string $message = ''): void
    {
        try {
            $admit();
        } catch (PreviewTokenRejected $e) {
            $this->assertSame($reason, $e->reason, $message);

            return;
        }
        $this->fail("admitted, expected '{$reason}'" . ($message === '' ? '' : " ({$message})"));
    }
}
