<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Context;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\Http\AuthMiddleware;
use Preflow\Auth\Http\ResolveUserMiddleware;
use Preflow\Auth\Http\TokenAuthMiddleware;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Auth\SessionGuard;
use Preflow\Auth\SyntheticUser;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\IdentityId;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Context\SyntheticIdentity;
use Preflow\Core\Context\Viewer;
use Preflow\Core\Exceptions\UnauthorizedHttpException;
use Preflow\Core\Http\CookieQueue;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Testing\ArraySession;
use Preflow\Testing\ContextFactory;
use Preflow\Testing\DataTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Since 0.81 who is signed in is the render context's viewer: ResolveUserMiddleware narrows the
 * request's frame to it, AuthManager reads it, and its setters are deprecated shims bound to the
 * frame they are called in. A role viewer (view as role) stays what it is.
 */
final class ViewerContextTest extends DataTestCase
{
    /** @var list<string> */
    private array $lockDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Contexts::reset();
        Deprecations::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        foreach ($this->lockDirs as $dir) {
            array_map(unlink(...), glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function test_resolve_user_narrows_the_frame_and_sets_the_attribute(): void
    {
        $user = new TestUser('u1');
        $member = new TestUser('m1');
        $auth = new AuthManager(['web' => self::guard($user), 'members' => self::guard($member)], 'web');
        $resolve = new ResolveUserMiddleware($auth, ['web' => self::guard($user), 'members' => self::guard($member)]);

        Contexts::run(ContextFactory::forTest(), function () use ($resolve, $auth, $user, $member): void {
            $handler = new Capture(function () use ($auth, $user, $member): void {
                $viewer = Contexts::current()->viewer;
                $this->assertSame($user, $viewer->for('web'));
                $this->assertSame($member, $viewer->for('members'));
                $this->assertSame($user, $viewer->primary());
                $this->assertSame($user, $auth->user());
                $this->assertSame('web', $auth->guardName());
                $this->assertSame(['web' => $user, 'members' => $member], $auth->users());
            });
            $resolve->process(self::request(), $handler);

            $this->assertSame(Contexts::current(), $handler->request?->getAttribute(RenderContext::class), 'the request carries the narrowed context');
            $this->assertSame($user, $handler->request?->getAttribute(Authenticatable::class));
            $this->assertSame('id:members:m1;id:web:u1@web', Contexts::current()->viewer->key(), 'the narrowing holds for the rest of the frame');
        });

        $this->assertNull($auth->user(), 'outside every render nobody is signed in');
        $this->assertSame([], $auth->users());
        $this->assertNull($auth->guardName());
    }

    public function test_auth_user_is_anonymous_in_an_internal_render_inside_a_signed_in_request(): void
    {
        $user = new TestUser('admin');
        $auth = new AuthManager(['web' => self::guard($user)], 'web');
        $resolve = new ResolveUserMiddleware($auth, ['web' => self::guard($user)]);

        Contexts::run(ContextFactory::forTest(), function () use ($resolve, $auth, $user): void {
            $resolve->process(self::request(), new Capture(function () use ($auth, $user): void {
                $inside = Contexts::run(
                    RenderContext::internal(Purpose::Audit, ContextFactory::forTest()->site, ContextFactory::forTest()->clock, '/about'),
                    static fn (): array => [$auth->user(), $auth->users(), $auth->guardName()],
                );
                $this->assertSame([null, [], null], $inside, 'the audit renders as an anonymous visitor');
                $this->assertSame($user, $auth->user(), 'the request is still signed in afterwards');
            }));
        });
    }

    public function test_set_user_is_a_deprecated_shim_bound_to_its_frame(): void
    {
        $outer = new TestUser('outer');
        $inner = new TestUser('inner');
        $auth = new AuthManager([], 'web');
        $notices = $this->collectDeprecations();

        try {
            Contexts::run(ContextFactory::forTest(), function () use ($auth, $outer, $inner): void {
                $auth->setUser($outer, 'web');
                Contexts::run(ContextFactory::forTest(), function () use ($auth, $inner): void {
                    $this->assertNull($auth->user(), 'a new frame starts with its own viewer');
                    $auth->setUser($inner, 'members');
                    $this->assertSame($inner, $auth->user());
                    $this->assertSame('members', $auth->guardName());
                    $this->assertSame($inner, Contexts::current()->viewer->primary(), 'it narrows the frame');
                });
                $this->assertSame($outer, $auth->user(), 'the outer viewer is back');
                $this->assertSame('web', $auth->guardName());

                $auth->setUser(null);
                $this->assertNull($auth->user());
                $this->assertTrue(Contexts::current()->viewer->isAnonymous());
            });
        } finally {
            restore_error_handler();
        }

        $this->assertNotEmpty($notices->list);
        $this->assertSame(
            'Preflow\Auth\AuthManager::setUser() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->as($viewer)).',
            $notices->list[0],
        );

        // Outside every frame there is nothing to set it in.
        $this->expectException(\Preflow\Core\Context\NoRenderContext::class);
        @$auth->setUser($outer);
    }

    public function test_add_user_is_a_deprecated_shim_bound_to_its_frame(): void
    {
        $staff = new TestUser('staff');
        $member = new TestUser('member');
        $auth = new AuthManager([], 'web');
        $notices = $this->collectDeprecations();

        try {
            Contexts::run(ContextFactory::forTest(), function () use ($auth, $staff, $member): void {
                $auth->setUser($staff, 'web');
                Contexts::run(ContextFactory::forTest()->as(new Viewer(['web' => $staff], 'web')), function () use ($auth, $staff, $member): void {
                    $auth->addUser($member, 'members');
                    $auth->addUser(new TestUser('other'), 'members');
                    $this->assertSame(['web' => $staff, 'members' => $member], $auth->users(), 'the first user of a guard stays');
                    $this->assertSame($staff, $auth->user(), 'the primary user stays');
                });
                $this->assertSame(['web' => $staff], $auth->users(), 'the outer frame never saw the member');
            });
        } finally {
            restore_error_handler();
        }

        $this->assertContains(
            'Preflow\Auth\AuthManager::addUser() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->as($viewer)).',
            $notices->list,
        );
    }

    public function test_a_synthetic_viewer_reads_as_synthetic_user(): void
    {
        $auth = new AuthManager([], 'web');
        $view = new RoleView('staff', ['editor', 'admin']);

        Contexts::run(ContextFactory::forTest()->asRole($view), function () use ($auth, $view): void {
            $user = $auth->user();
            $this->assertInstanceOf(SyntheticUser::class, $user);
            $this->assertSame($view, $user->view);
            $this->assertSame(SyntheticIdentity::PREFIX . 'staff:admin,editor', $user->getAuthId());
            $this->assertSame(['admin', 'editor'], $user->roles());
            $this->assertSame(['admin', 'editor'], $user->getRoles());
            $this->assertTrue($user->hasRole('editor'));
            $this->assertFalse($user->hasRole('owner'));
            $this->assertSame('', $user->getPasswordHash());
            // The population is no guard name: the role view counts as signed in with a reserved one.
            $this->assertSame('preflow:synthetic:staff', $auth->guardName());
            $this->assertSame(['preflow:synthetic:staff' => $user->getAuthId()], array_map(static fn (Authenticatable $u): string => $u->getAuthId(), $auth->users()));

            try {
                $user->email = 'x@site.test';
                $this->fail('a synthetic user is read-only');
            } catch (\LogicException) {
            }

            // The deprecated setters never replace a role view.
            foreach ([
                static fn () => @$auth->setUser(new TestUser('real'), 'web'),
                static fn () => @$auth->setUser(null),
                static fn () => @$auth->setUser(new SyntheticUser(new RoleView('staff', ['owner'])), 'staff'),
                static fn () => @$auth->addUser(new TestUser('m'), 'members'),
            ] as $i => $set) {
                try {
                    $set();
                    $this->fail("setter {$i}: replacing a role view must be refused");
                } catch (\LogicException $e) {
                    $this->assertStringContainsString('role view', $e->getMessage());
                }
            }
            $this->assertTrue(Contexts::current()->viewer->isSynthetic());
        });

        // The shim given a SyntheticUser makes the frame its role view: writes skipped, mail captured.
        Contexts::run(ContextFactory::forTest(), function () use ($auth, $view): void {
            @$auth->setUser(new SyntheticUser($view), 'web');
            $this->assertSame($view->roles, Contexts::current()->viewer->roleView?->roles);
            $this->assertSame(\Preflow\Core\Context\WritePolicy::Skip, Contexts::current()->writes);
            $this->assertSame(\Preflow\Core\Context\OutboxPolicy::Capture, Contexts::current()->outbox);
        });
    }

    public function test_a_restored_viewer_is_looked_up_again_by_guard_and_id(): void
    {
        $provider = new class implements UserProviderInterface {
            public int $lookups = 0;

            public function findById(string $id): ?Authenticatable
            {
                ++$this->lookups;

                return $id === 'u1' ? new TestUser('u1', roles: ['editor']) : null;
            }

            public function findByCredentials(array $credentials): ?Authenticatable
            {
                return null;
            }
        };
        $auth = new AuthManager(['web' => self::guard(null)], 'web', providers: ['web' => $provider]);
        $restored = unserialize(serialize(new Viewer(['web' => new TestUser('u1')], 'web')));
        $this->assertInstanceOf(IdentityId::class, $restored->for('web'));

        Contexts::run(ContextFactory::forTest()->as($restored), function () use ($auth, $provider): void {
            $user = $auth->user();
            $this->assertInstanceOf(TestUser::class, $user, 'the stored user, never the bare id');
            $this->assertSame(['editor'], $user->getRoles());
            $this->assertSame($user, $auth->user(), 'looked up once per frame');
            $this->assertSame(1, $provider->lookups);
            $this->assertSame('web', $auth->guardName());
        });

        $gone = unserialize(serialize(new Viewer(['web' => new TestUser('deleted')], 'web')));
        Contexts::run(ContextFactory::forTest()->as($gone), function () use ($auth): void {
            $this->assertNull($auth->user(), 'an id nobody has any more is anonymous');
            $this->assertSame([], $auth->users());
            $this->assertNull($auth->guardName());
        });

        $noProvider = unserialize(serialize(new Viewer(['api' => new TestUser('u1')], 'api')));
        Contexts::run(ContextFactory::forTest()->as($noProvider), function () use ($auth): void {
            $this->assertNull($auth->user(), 'a guard without a provider cannot vouch for the id');
        });
    }

    public function test_guards_refuse_to_resolve_a_synthetic_id(): void
    {
        $synthetic = SyntheticIdentity::PREFIX . 'staff:admin';
        $anyone = new class implements UserProviderInterface {
            public function findById(string $id): ?Authenticatable
            {
                return new TestUser($id, roles: ['admin']);
            }

            public function findByCredentials(array $credentials): ?Authenticatable
            {
                return null;
            }
        };
        $guard = new SessionGuard($anyone, new NativePasswordHasher());
        $session = new ArraySession();
        $session->start();
        $session->set('_auth_user_id', $synthetic);
        $request = self::request()->withAttribute(SessionInterface::class, $session);

        $this->assertNull($guard->user($request), 'a session holding a reserved id is nobody');
        try {
            $guard->login(new TestUser($synthetic), $request);
            $this->fail('signing in a reserved id must be refused');
        } catch (\LogicException) {
        }
        $this->assertSame($synthetic, $session->get('_auth_user_id'), 'nothing was written');

        // Any guard (a site's own too): what it returns with a reserved id is nobody.
        $custom = self::guard(new TestUser($synthetic));
        $auth = new AuthManager(['session' => $guard, 'custom' => $custom], 'session');
        Contexts::run(ContextFactory::forTest(), function () use ($auth, $guard, $custom, $request): void {
            (new ResolveUserMiddleware($auth, ['session' => $guard, 'custom' => $custom]))->process($request, new Capture(function () use ($auth): void {
                $this->assertNull($auth->user());
                $this->assertTrue(Contexts::current()->viewer->isAnonymous());
            }));
            $this->expectException(UnauthorizedHttpException::class);
            (new AuthMiddleware($auth, 'custom'))->process($request, new Capture(static fn () => null));
        });
    }

    /**
     * A sub-request inside a view-as-role render (a component, a lazy part) carries the editor's
     * session cookie. The role viewer stays: no guard is asked (a remember-me sign-in would write
     * and set cookies), the request carries the synthetic user, and the routes behind
     * AuthMiddleware and TokenAuthMiddleware stay closed to it.
     */
    public function test_a_role_viewer_is_kept_by_the_identity_middlewares(): void
    {
        $editor = new TestUser('editor', roles: ['admin']);
        $asked = new class ($editor) implements GuardInterface {
            public int $asked = 0;

            public function __construct(private readonly Authenticatable $user) {}

            public function user(ServerRequestInterface $request): ?Authenticatable
            {
                ++$this->asked;

                return $this->user;
            }

            public function validate(array $credentials): bool
            {
                return false;
            }

            public function login(Authenticatable $user, ServerRequestInterface $request): void {}

            public function logout(ServerRequestInterface $request): void {}
        };
        $auth = new AuthManager(['web' => $asked], 'web');
        $role = ContextFactory::forTest()->asRole(new RoleView('member', ['gold']));

        Contexts::run($role, function () use ($auth, $asked): void {
            $handler = new Capture(function () use ($auth): void {
                $this->assertInstanceOf(SyntheticUser::class, $auth->user());
            });
            (new ResolveUserMiddleware($auth, ['web' => $asked]))->process(self::request(), $handler);
            $this->assertSame(0, $asked->asked, 'no guard is asked under a role view');
            $this->assertTrue(Contexts::current()->viewer->isSynthetic());
            $this->assertInstanceOf(SyntheticUser::class, $handler->request?->getAttribute(Authenticatable::class));
            $this->assertSame('preflow:synthetic:member', $handler->request?->getAttribute(AuthManager::GUARD_ATTRIBUTE));
            $this->assertSame(Contexts::current(), $handler->request?->getAttribute(RenderContext::class));

            foreach (['auth' => new AuthMiddleware($auth, 'web'), 'token' => new TokenAuthMiddleware($auth, 'web')] as $name => $middleware) {
                try {
                    $middleware->process(self::request(), new Capture(static fn () => null));
                    $this->fail("{$name}: a role view must not pass a guarded route");
                } catch (UnauthorizedHttpException) {
                }
            }
            $this->assertSame(0, $asked->asked, 'still no guard asked');
            $this->assertTrue(Contexts::current()->viewer->isSynthetic());
        });
    }

    /**
     * A guest-only route (a members' login page) under a role view is decided by the role view's
     * user, never by the session the request carries: no guard is asked. The role view counts as
     * signed in, so it is sent away like any signed-in user.
     */
    public function test_guest_middleware_asks_no_guard_under_a_role_view(): void
    {
        $asked = new CountingGuard(new TestUser('editor', roles: ['admin']));
        $auth = new AuthManager(['web' => $asked], 'web');
        $guest = new \Preflow\Auth\Http\GuestMiddleware($auth, '/home');

        $status = Contexts::run(ContextFactory::forTest()->asRole(new RoleView('member', ['gold'])), static fn (): int => $guest->process(self::request('/login'), new Capture(static fn () => null))->getStatusCode());
        $this->assertSame(302, $status, 'the role view is signed in');
        $this->assertSame(0, $asked->asked, 'the editor\'s session was never read');

        // Without a role view, the guard still decides.
        $status = Contexts::run(ContextFactory::forTest(), static fn (): int => $guest->process(self::request('/login'), new Capture(static fn () => null))->getStatusCode());
        $this->assertSame([302, 1], [$status, $asked->asked]);
    }

    /**
     * Inside a role view the session the request carries is the editor's: SessionGuard neither
     * signs in nor out there (a "Sign out" button in a previewed page), and the session is untouched.
     */
    public function test_session_guard_login_and_logout_are_refused_in_a_role_view(): void
    {
        $guard = new SessionGuard(new class implements UserProviderInterface {
            public function findById(string $id): ?Authenticatable
            {
                return new TestUser($id);
            }

            public function findByCredentials(array $credentials): ?Authenticatable
            {
                return null;
            }
        }, new NativePasswordHasher());
        $session = new ArraySession();
        $session->start();
        $session->set('_auth_user_id', 'editor');
        $id = $session->getId();
        $request = self::request()->withAttribute(SessionInterface::class, $session);

        Contexts::run(ContextFactory::forTest()->asRole(new RoleView('member', ['gold'])), function () use ($guard, $request): void {
            foreach (['login' => static fn () => $guard->login(new TestUser('other'), $request), 'logout' => static fn () => $guard->logout($request)] as $name => $call) {
                try {
                    $call();
                    $this->fail("{$name} must be refused in a role view");
                } catch (\LogicException $e) {
                    $this->assertStringContainsString('role view', $e->getMessage(), $name);
                }
            }
        });

        $this->assertSame('editor', $session->get('_auth_user_id'), 'still signed in');
        $this->assertSame($id, $session->getId(), 'never regenerated or invalidated');
        $this->assertTrue($session->isStarted());
    }

    /**
     * A role view's population never reads as a guard name: guardName(), the keys of users() and
     * the request's GUARD_ATTRIBUTE give a reserved name, and guard() under a role view never
     * reaches the session the request carries, also for a guard named like the population.
     */
    public function test_guard_of_guard_name_under_a_role_view_never_reaches_a_real_session(): void
    {
        $staff = new CountingGuard(new TestUser('editor', roles: ['admin']));
        $members = new CountingGuard(new TestUser('m-1', roles: ['member']));
        $auth = new AuthManager(['staff' => $staff, 'members' => $members], 'staff');
        $request = self::request();

        Contexts::run(ContextFactory::forTest()->asRole(new RoleView('staff', ['editor'])), function () use ($auth, $request): void {
            $user = $auth->user();
            $this->assertInstanceOf(SyntheticUser::class, $user);
            $this->assertSame('preflow:synthetic:staff', $auth->guardName());
            $this->assertSame(AuthManager::roleViewGuard(new RoleView('staff', ['editor'])), $auth->guardName());
            $this->assertSame(['preflow:synthetic:staff'], array_keys($auth->users()));

            $this->assertSame($user, $auth->guard($auth->guardName())->user($request), 'the role view\'s own guard gives its user');
            foreach (['staff', 'members', null] as $name) {
                $guard = $auth->guard($name);
                $this->assertInstanceOf(\Preflow\Auth\RoleViewGuard::class, $guard, (string) $name);
                $this->assertNull($guard->user($request), ($name ?? 'default') . ': a configured guard gives nobody inside a role view');
                $this->assertFalse($guard->validate(['email' => 'editor', 'password' => 'x']));
            }
            foreach (['login' => static fn () => $auth->guard('members')->login(new TestUser('m-1'), $request), 'logout' => static fn () => $auth->guard($auth->guardName())->logout($request)] as $what => $call) {
                try {
                    $call();
                    $this->fail("{$what} must be refused inside a role view");
                } catch (\LogicException $e) {
                    $this->assertStringContainsString('role view', $e->getMessage(), $what);
                }
            }
            try {
                $auth->guard('nope');
                $this->fail('an unknown guard stays unknown');
            } catch (\RuntimeException) {
            }
            try {
                $auth->guard('preflow:synthetic:member');
                $this->fail('another role view\'s reserved name is no guard here');
            } catch (\RuntimeException) {
            }
        });
        $this->assertSame([0, 0], [$staff->asked, $members->asked], 'no guard read the request\'s session');

        // Outside a role view the guards are the real ones again.
        Contexts::run(ContextFactory::forTest(), function () use ($auth, $request, $staff): void {
            $this->assertSame($staff, $auth->guard('staff'));
            $this->assertSame('editor', $auth->guard()->user($request)?->getAuthId());
        });

        // No configured guard may take a name reserved for role views.
        $this->expectException(\InvalidArgumentException::class);
        new AuthManager(['preflow:synthetic:staff' => $staff], 'preflow:synthetic:staff');
    }

    /**
     * A guest-only page of one guard (the members' login) sends a role view away only when the
     * role view counts as signed in with that guard: its population is the guard's.
     */
    public function test_guest_middleware_compares_the_role_views_population_with_its_guard(): void
    {
        $auth = new AuthManager(['staff' => new CountingGuard(null), 'members' => new CountingGuard(null)], 'staff');
        $access = new \Preflow\Auth\Access\Access($auth, new \Preflow\Auth\Access\PermissionRegistry(), false, ['staff' => 'staff', 'members' => 'members']);
        $status = static fn (\Preflow\Auth\Http\GuestMiddleware $guest, RoleView $view): int => Contexts::run(
            ContextFactory::forTest()->asRole($view),
            static fn (): int => $guest->process(self::request('/members/login'), new Capture(static fn () => null))->getStatusCode(),
        );
        $membersLogin = new \Preflow\Auth\Http\GuestMiddleware($auth, '/members', 'members', $access);
        $staffLogin = new \Preflow\Auth\Http\GuestMiddleware($auth, '/admin', 'staff', $access);

        $this->assertSame(200, $status($membersLogin, new RoleView('staff', ['admin'])), 'a staff role view still sees the members\' login');
        $this->assertSame(302, $status($membersLogin, new RoleView('member', ['gold'])), 'a member role view is signed in there');
        $this->assertSame(302, $status($staffLogin, new RoleView('staff', ['admin'])));
        $this->assertSame(200, $status($staffLogin, new RoleView('member', ['gold'])));
        // Without Access the role view counts as signed in with every guard, as before.
        $this->assertSame(302, $status(new \Preflow\Auth\Http\GuestMiddleware($auth, '/members', 'members'), new RoleView('staff', ['admin'])));
    }

    /**
     * A guarded route of one guard lets a role view through when the role view counts as signed in
     * with that guard (its population is the guard's), without asking a guard or narrowing; one of
     * another population is treated as signed out. A members-only page and its guest-only login
     * never send a role view round in a loop: exactly one of them answers it.
     */
    public function test_auth_middleware_lets_a_role_view_of_its_population_through(): void
    {
        $staffGuard = new CountingGuard(new TestUser('editor', roles: ['admin']));
        $membersGuard = new CountingGuard(null);
        $auth = new AuthManager(['staff' => $staffGuard, 'members' => $membersGuard], 'staff');
        $access = new \Preflow\Auth\Access\Access($auth, new \Preflow\Auth\Access\PermissionRegistry(), false, ['staff' => 'staff', 'members' => 'members']);
        $membersPage = new AuthMiddleware($auth, 'members', access: $access);
        $staffPage = new AuthMiddleware($auth, 'staff', access: $access);
        $membersLogin = new \Preflow\Auth\Http\GuestMiddleware($auth, '/members/home', 'members', $access);
        $answer = static function (\Psr\Http\Server\MiddlewareInterface $middleware, RoleView $view): array {
            return Contexts::run(ContextFactory::forTest()->asRole($view), static function () use ($middleware): array {
                $handler = new Capture(static fn () => null);
                try {
                    $status = $middleware->process(self::request('/members/home'), $handler)->getStatusCode();
                } catch (UnauthorizedHttpException) {
                    $status = 401;
                }

                return [$status, $handler->request?->getAttribute(Authenticatable::class)?->getAuthId(), Contexts::current()->viewer->isSynthetic()];
            });
        };

        $this->assertSame([200, 'preflow:synthetic:member:gold', true], $answer($membersPage, new RoleView('member', ['gold'])), 'a member role view passes a members page as itself');
        $this->assertSame([401, null, true], $answer($staffPage, new RoleView('member', ['gold'])), 'a member role view is signed out on a staff page');
        $this->assertSame([401, null, true], $answer($membersPage, new RoleView('staff', ['admin'])), 'a staff role view is signed out on a members page');
        $this->assertSame([200, 'preflow:synthetic:staff:admin', true], $answer($staffPage, new RoleView('staff', ['admin'])));
        // No loop: the members page passes a member role view, its login sends it on; a staff role view gets the login, not the page.
        $this->assertSame(302, $answer($membersLogin, new RoleView('member', ['gold']))[0]);
        $this->assertSame(200, $answer($membersLogin, new RoleView('staff', ['admin']))[0]);
        $this->assertSame([0, 0], [$staffGuard->asked, $membersGuard->asked], 'no guard was asked');
        // Without Access a role view never passes, as before.
        $this->assertSame(401, $answer(new AuthMiddleware($auth, 'members'), new RoleView('member', ['gold']))[0]);
    }

    /** A template reading the user's fields renders in a role view, also with strict variables. */
    public function test_a_synthetic_user_reads_in_a_strict_template(): void
    {
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
            'greet' => 'Hello {{ user.name }}|{{ user.email }}|{{ user.id }}|{{ user.roles|join(",") }}|{{ user.getAuthId }}|{{ user.hasRole("gold") ? "gold" : "-" }}',
        ]), ['strict_variables' => true]);
        $user = new SyntheticUser(new RoleView('member', ['gold']));

        $this->assertSame('Hello ||preflow:synthetic:member:gold|gold|preflow:synthetic:member:gold|gold', $twig->render('greet', ['user' => $user]));
        $this->assertNull($user->name);
        $this->assertTrue(isset($user->name));
        $this->assertFalse(isset($user->getRoles), 'method names are left to the methods');

        // A name a get/is/has method answers is left to that method too: strict {{ user.authId }} prints the id.
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
            'ids' => '{{ user.authId }}|{{ user.passwordHash }}|{{ user.population }}|{{ user.roles|join(",") }}|{{ user.role ?? "-" }}',
        ]), ['strict_variables' => true]);
        $this->assertSame('preflow:synthetic:member:gold||member|gold|-', $twig->render('ids', ['user' => $user]));
        $this->assertFalse(isset($user->authId));
        $this->assertFalse(isset($user->passwordHash));
        $this->assertTrue(isset($user->role), 'hasRole() needs an argument: role reads as a field');
    }

    /**
     * An internal render (its caller chose the viewer) asks no guard, even when its request
     * carries a session: the admin is never signed in by a copied request.
     */
    public function test_an_internal_render_asks_no_guard(): void
    {
        $asked = new CountingGuard(new TestUser('admin'));
        $auth = new AuthManager(['web' => $asked], 'web');
        $resolve = new ResolveUserMiddleware($auth, ['web' => $asked]);
        $session = new ArraySession();
        $session->start();

        $internal = RenderContext::internal(Purpose::Audit, ContextFactory::forTest()->site, ContextFactory::forTest()->clock, '/about');
        Contexts::run($internal, function () use ($resolve, $auth, $session): void {
            $handler = new Capture(function () use ($auth): void {
                $this->assertNull($auth->user());
            });
            $resolve->process(self::request('/about')->withAttribute(SessionInterface::class, $session), $handler);
            $this->assertNull($handler->request?->getAttribute(Authenticatable::class));
            $this->assertSame(Contexts::current(), $handler->request?->getAttribute(RenderContext::class));
        });
        $this->assertSame(0, $asked->asked);
    }

    /**
     * Regression (0.75, kept by the frames): a request signed back in from its remember-me cookie
     * queues the rotated cookie, then renders a page internally (the SEO audit's step), whose own
     * ResolveUserMiddleware runs in the render's frame; the rotated cookie still reaches the
     * request's response, or the browser keeps a token already rotated away.
     */
    public function test_remember_me_rotation_cookie_survives_an_internal_render(): void
    {
        /** @var Migration $migration */
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_10_23_000001_auth_remember.php';
        $migration->up(new Schema($this->getPdo()));
        $lockDir = sys_get_temp_dir() . '/preflow_viewer_locks_' . uniqid();
        $this->lockDirs[] = $lockDir;
        $remember = new RememberMe($this->dataManager(), 'default', static fn (): int => 1_000_000, $lockDir);
        $provider = new class implements UserProviderInterface {
            public function findById(string $id): ?Authenticatable
            {
                return $id === 'user-1' ? new TestUser('user-1') : null;
            }

            public function findByCredentials(array $credentials): ?Authenticatable
            {
                return null;
            }
        };
        // As the application builds it: the guard queues into the current frame's cookie slot.
        $guard = new SessionGuard($provider, new NativePasswordHasher(), '_auth_user_id', [], $remember, 'member', ['cookie' => 'ss_member', 'days' => 30], CookieQueue::ofCurrentFrame());
        $auth = new AuthManager(['member' => $guard], 'member');
        $resolve = new ResolveUserMiddleware($auth, ['member' => $guard]);
        $session = new ArraySession();
        $session->start();
        $request = self::request()->withCookieParams(['ss_member' => $remember->issue('member', 'user-1')])->withAttribute(SessionInterface::class, $session);

        $response = Contexts::run(ContextFactory::forTest(), function () use ($resolve, $auth, $request): ResponseInterface {
            return $resolve->process($request, new Capture(function () use ($resolve, $auth): void {
                $this->assertSame('user-1', $auth->user()?->getAuthId());
                // The internal render: its own frame, its own pipeline, a request without a session.
                Contexts::run(
                    RenderContext::internal(Purpose::Audit, ContextFactory::forTest()->site, ContextFactory::forTest()->clock, '/about'),
                    function () use ($resolve, $auth): void {
                        $resolve->process(self::request('/about'), new Capture(function () use ($auth): void {
                            $this->assertNull($auth->user());
                        }));
                    },
                );
                $this->assertSame('user-1', $auth->user()?->getAuthId());
            }));
        });

        $cookies = array_map(static fn (string $c): string => strstr($c, '=', true) ?: $c, $response->getHeader('Set-Cookie'));
        $this->assertContains('ss_member', $cookies, 'the rotated remember-me cookie reaches the response');
        $this->assertContains(SessionGuard::SIGNED_IN_COOKIE, $cookies);
    }

    private static function request(string $path = '/'): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', 'http://localhost' . $path);
    }

    private static function guard(?Authenticatable $user): GuardInterface
    {
        return new class ($user) implements GuardInterface {
            public function __construct(private readonly ?Authenticatable $user) {}

            public function user(ServerRequestInterface $request): ?Authenticatable
            {
                return $this->user;
            }

            public function validate(array $credentials): bool
            {
                return false;
            }

            public function login(Authenticatable $user, ServerRequestInterface $request): void {}

            public function logout(ServerRequestInterface $request): void {}
        };
    }

    /** Collects E_USER_DEPRECATED messages (silenced ones too) until restore_error_handler(). */
    private function collectDeprecations(): object
    {
        $notices = new class {
            /** @var list<string> */
            public array $list = [];
        };
        set_error_handler(static function (int $errno, string $message) use ($notices): bool {
            $notices->list[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        return $notices;
    }
}

/** A handler that runs $then and keeps the request it was given. */
final class Capture implements RequestHandlerInterface
{
    public ?ServerRequestInterface $request = null;

    public function __construct(private readonly \Closure $then) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;
        ($this->then)();

        return new Response(200);
    }
}

/** A guard that counts how often it was asked. */
final class CountingGuard implements GuardInterface
{
    public int $asked = 0;

    public function __construct(private readonly ?Authenticatable $user) {}

    public function user(ServerRequestInterface $request): ?Authenticatable
    {
        ++$this->asked;

        return $this->user;
    }

    public function validate(array $credentials): bool
    {
        return false;
    }

    public function login(Authenticatable $user, ServerRequestInterface $request): void {}

    public function logout(ServerRequestInterface $request): void {}
}
