<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Preflow\Auth\Support\ResolvedUsers;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Context\SyntheticIdentity;
use Preflow\Core\Context\Viewer;
use Preflow\Core\Identity;

/**
 * The guards, and who is signed in. Since 0.81 who is signed in is the current render context's
 * viewer (Contexts::current()->viewer), which the request's identity middleware narrows
 * (ResolveUserMiddleware, AuthMiddleware, TokenAuthMiddleware): user(), users() and guardName()
 * read it, so a render in its own frame (the SEO audit, a preview) sees its own viewer and never
 * the request's around it. Outside every render nobody is signed in.
 *
 * What the viewer holds is given as a user this way: a user the middleware put there as is; a
 * role view (view as role) as a SyntheticUser; a bare id (an IdentityId of a restored context,
 * or another Identity) is looked up again with the guard's provider, once per frame, and counts
 * as nobody when that finds no one or the guard has no provider. Ids reserved for role views
 * are never looked up.
 *
 * A role view is signed in with no guard (since 0.81.0): its guard name is a reserved one,
 * roleViewGuard() ('preflow:synthetic:{population}'), never its population, which may be a
 * configured guard's name ('staff'). guardName(), the keys of users() and the request's
 * GUARD_ATTRIBUTE give it, and guard() under a role view hands out a RoleViewGuard for every
 * name: the reserved one gives the role view's user, a configured one nobody, and none signs
 * in or out, so no code reaches the session the request carries (the editor's) through a guard.
 */
final class AuthManager
{
    /** Request attribute naming the guard that signed in the request's Authenticatable. */
    public const string GUARD_ATTRIBUTE = 'preflow.auth.guard';

    /**
     * @param array<string, GuardInterface|LazyGuard> $guards a LazyGuard is built on first use (since 0.66)
     * @param array<string, string> $loginPaths each guard's `login` path from config/auth.php, by guard name
     * @param array<string, UserProviderInterface> $providers each guard's user provider, by guard name
     */
    public function __construct(
        private array $guards,
        private readonly string $defaultGuard,
        private readonly array $loginPaths = [],
        private readonly array $providers = [],
    ) {
        foreach (array_keys($guards) as $name) {
            if (str_starts_with((string) $name, SyntheticIdentity::PREFIX)) {
                throw new \InvalidArgumentException("A guard name must not start with '" . SyntheticIdentity::PREFIX . "', which names role views (view as role); got '{$name}'.");
            }
        }
    }

    /**
     * The guard name a role view (view as role) of $view's population counts as signed in with:
     * 'preflow:synthetic:{population}', which no configured guard may have.
     *
     * @since 0.81.0
     */
    public static function roleViewGuard(RoleView $view): string
    {
        return SyntheticIdentity::PREFIX . $view->population;
    }

    /** The user provider of a guard (guards.<name>.provider); null names the default guard. Null when unknown. */
    public function provider(?string $guard = null): ?UserProviderInterface
    {
        return $this->providers[$guard ?? $this->defaultGuard] ?? null;
    }

    /** Where signed-out browsers of a guard are sent (guards.<name>.login); null when it has none. Null names the default guard. */
    public function loginPath(?string $guard = null): ?string
    {
        $path = $this->loginPaths[$guard ?? $this->defaultGuard] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * The guard $name (null: the default guard). Under a role view (view as role) a RoleViewGuard
     * stands in for every name (since 0.81.0): roleViewGuard()'s gives the role view's user, any
     * other nobody, and none signs in or out, so the session the request carries is never read.
     * So does a render admitted by a preview token (a PreviewGrant on the context): every guard
     * gives nobody there (since 0.81.0).
     *
     * @throws \RuntimeException for a guard that is not configured
     */
    public function guard(?string $name = null): GuardInterface
    {
        $name ??= $this->defaultGuard;
        $viewer = self::viewer();
        // A render admitted by a preview token (PreviewGrant: a visitor's or a role's view) reads no
        // guard either: the session and cookies the request carries are the editor's (since 0.81.0).
        if ($viewer?->roleView !== null || self::previewed()) {
            if (!isset($this->guards[$name]) && ($viewer?->roleView === null || $name !== self::roleViewGuard($viewer->roleView))) {
                throw new \RuntimeException("Auth guard [{$name}] is not configured.");
            }
            $user = $this->user();

            return new RoleViewGuard($viewer?->roleView !== null && $name === self::roleViewGuard($viewer->roleView) && $user instanceof SyntheticUser ? $user : null);
        }
        if (!isset($this->guards[$name])) {
            throw new \RuntimeException("Auth guard [{$name}] is not configured.");
        }

        return $this->guards[$name] = LazyGuard::of($this->guards[$name]);
    }

    /**
     * The user signed in with $guard (null: the default guard) for the rest of the current render
     * frame, as its only one; null signs everyone out of it. A SyntheticUser makes the frame a
     * role view of its roles. A frame that renders as a role view keeps it: the shim never
     * changes it (a LogicException), so code still on setUser() (an admin's own sign-in check)
     * fails closed inside a role view.
     *
     * @deprecated 0.81, removed in 0.83: narrow the frame's viewer, Contexts::narrow(fn ($ctx) => $ctx->as($viewer))
     *
     * @throws \Preflow\Core\Context\NoRenderContext outside every render
     * @throws \LogicException when the frame renders as a role view
     */
    public function setUser(?Authenticatable $user, ?string $guard = null): void
    {
        Deprecations::once('Preflow\Auth\AuthManager::setUser() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->as($viewer)).');
        $guard ??= $this->defaultGuard;
        Contexts::narrow(static function (RenderContext $ctx) use ($user, $guard): RenderContext {
            if ($ctx->viewer->isSynthetic()) {
                throw new \LogicException('AuthManager::setUser() cannot replace the role view (view as role) this render runs as.');
            }

            return $ctx->as($user === null ? Viewer::anonymous() : self::viewerOf([$guard => $user], $guard));
        });
    }

    /**
     * Another guard's user, signed in beside the ones already there for the rest of the current
     * render frame; a guard that has a user keeps it. Without anyone signed in yet, $user is the
     * primary one (0.80 kept it out of user() until setUser() named one).
     *
     * @deprecated 0.81, removed in 0.83: narrow the frame's viewer, Contexts::narrow(fn ($ctx) => $ctx->as($viewer))
     *
     * @throws \Preflow\Core\Context\NoRenderContext outside every render
     * @throws \LogicException when the frame renders as a role view
     */
    public function addUser(Authenticatable $user, string $guard): void
    {
        Deprecations::once('Preflow\Auth\AuthManager::addUser() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->as($viewer)).');
        Contexts::narrow(static function (RenderContext $ctx) use ($user, $guard): RenderContext {
            $viewer = $ctx->viewer;
            if ($viewer->isSynthetic()) {
                throw new \LogicException('AuthManager::addUser() cannot add a user to a role view (view as role); it renders as its roles only.');
            }
            if (isset($viewer->identities[$guard])) {
                return $ctx;
            }

            return $ctx->as(new Viewer($viewer->identities + [$guard => $user], $viewer->primaryGuard));
        });
    }

    /**
     * Every user signed in for the current render, by guard, the primary one first. Empty outside
     * every render.
     *
     * @return array<string, Authenticatable>
     */
    public function users(): array
    {
        $viewer = self::viewer();
        $primary = $viewer === null ? null : self::primaryGuard($viewer);
        if ($viewer === null || $primary === null) {
            return [];
        }
        $users = [];
        foreach ([$primary => $viewer->identities[$primary]] + $viewer->identities as $guard => $identity) {
            $user = $this->userOf((string) $guard, $identity);
            if ($user !== null) {
                $users[self::nameOf($viewer, (string) $guard)] = $user;
            }
        }

        return $users;
    }

    /** The guard of user(); null when user() is null. A role view's is roleViewGuard(), never its population. */
    public function guardName(): ?string
    {
        $viewer = self::viewer();
        $guard = $viewer === null ? null : self::primaryGuard($viewer);

        return $guard !== null && $this->userOf($guard, $viewer->identities[$guard]) !== null ? self::nameOf($viewer, $guard) : null;
    }

    /** The guard name $guard of $viewer is given as: a role view's reserved one (roleViewGuard()), else $guard. */
    private static function nameOf(Viewer $viewer, string $guard): string
    {
        return $viewer->roleView !== null ? self::roleViewGuard($viewer->roleView) : $guard;
    }

    /**
     * Whether $identity's id is reserved for role views (SyntheticIdentity::PREFIX): no guard
     * resolves it and no request is signed in with it.
     *
     * @since 0.81.0
     */
    public static function isReserved(Identity $identity): bool
    {
        return str_starts_with($identity->getAuthId(), SyntheticIdentity::PREFIX);
    }

    /**
     * The guard bearer-token routes use: the preferred one (config auth.api_guard, which must be a TokenGuard), else the first
     * configured TokenGuard. Null when there is none.
     */
    public function tokenGuardName(?string $preferred = null): ?string
    {
        if ($preferred !== null) {
            // A named guard that is no TokenGuard is refused, not replaced: a session guard must never pass a token route.
            return self::isTokenGuard($this->guards[$preferred] ?? null) ? $preferred : null;
        }
        foreach ($this->guards as $name => $guard) {
            if (self::isTokenGuard($guard)) {
                return (string) $name;
            }
        }

        return null;
    }

    /** A built guard, or a guard built on first use whose class is a TokenGuard (it isn't built to find out). */
    private static function isTokenGuard(GuardInterface|LazyGuard|null $guard): bool
    {
        return $guard instanceof TokenGuard || ($guard instanceof LazyGuard && $guard->is(TokenGuard::class));
    }

    public function defaultGuardName(): string
    {
        return $this->defaultGuard;
    }

    /** The primary user signed in for the current render; null when nobody is, and outside every render. */
    public function user(): ?Authenticatable
    {
        $viewer = self::viewer();
        $guard = $viewer === null ? null : self::primaryGuard($viewer);

        return $guard === null ? null : $this->userOf($guard, $viewer->identities[$guard]);
    }

    /**
     * The viewer of $users (by guard) with $primary's user as the primary one; a SyntheticUser
     * alone is its role view.
     *
     * @param non-empty-array<string, Authenticatable> $users
     * @internal the identity middleware, the shims and preflow/testing's actingAs()
     */
    public static function viewerOf(array $users, string $primary): Viewer
    {
        $only = count($users) === 1 ? reset($users) : null;

        return $only instanceof SyntheticUser ? Viewer::synthetic($only->view) : new Viewer($users, $primary);
    }

    /** The current render's viewer; null outside every render (nobody is signed in there). */
    private static function viewer(): ?Viewer
    {
        return Contexts::peek()?->viewer;
    }

    /** Whether the current render was admitted by a preview token (it has a PreviewGrant); false outside every render. */
    private static function previewed(): bool
    {
        return Contexts::peek()?->get(\Preflow\Core\Context\Preview\PreviewGrant::class) !== null;
    }

    /** The guard whose identity is $viewer's primary one; null for the anonymous viewer. */
    private static function primaryGuard(Viewer $viewer): ?string
    {
        $primary = $viewer->primary();
        $guard = $primary === null ? false : array_search($primary, $viewer->identities, true);

        return $guard === false ? null : (string) $guard;
    }

    /** $identity of $guard as a user (see the class docblock); null when it stands for nobody. */
    private function userOf(string $guard, Identity $identity): ?Authenticatable
    {
        if ($identity instanceof SyntheticIdentity) {
            return Contexts::frame()->slot(ResolvedUsers::class, static fn (): ResolvedUsers => new ResolvedUsers())
                ->remember('synthetic', $identity->getAuthId(), static fn (): SyntheticUser => new SyntheticUser($identity->view));
        }
        if ($identity instanceof Authenticatable) {
            return $identity;
        }
        $provider = $this->providers[$guard] ?? null;
        if ($provider === null || self::isReserved($identity)) {
            return null;
        }
        $id = $identity->getAuthId();
        $user = Contexts::frame()->slot(ResolvedUsers::class, static fn (): ResolvedUsers => new ResolvedUsers())
            ->remember($guard, $id, static fn (): ?Authenticatable => $provider->findById($id));

        return $user === null || self::isReserved($user) ? null : $user;
    }
}
