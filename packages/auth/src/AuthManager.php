<?php

declare(strict_types=1);

namespace Preflow\Auth;

final class AuthManager
{
    /** Request attribute naming the guard that signed in the request's Authenticatable. */
    public const string GUARD_ATTRIBUTE = 'preflow.auth.guard';

    private ?Authenticatable $resolvedUser = null;

    private ?string $resolvedGuard = null;

    /** @var array<string, Authenticatable> every user resolved for this request, by guard (the first is the primary) */
    private array $resolvedUsers = [];

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
    ) {}

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

    public function guard(?string $name = null): GuardInterface
    {
        $name ??= $this->defaultGuard;
        if (!isset($this->guards[$name])) {
            throw new \RuntimeException("Auth guard [{$name}] is not configured.");
        }

        return $this->guards[$name] = LazyGuard::of($this->guards[$name]);
    }

    /** The user signed in for this request, and the guard that signed them in. */
    public function setUser(?Authenticatable $user, ?string $guard = null): void
    {
        $this->resolvedUser = $user;
        $this->resolvedGuard = $user === null ? null : ($guard ?? $this->defaultGuard);
        $this->resolvedUsers = $user === null ? [] : [$this->resolvedGuard => $user];
    }

    /** Another guard's user, signed in for this request beside the primary one. */
    public function addUser(Authenticatable $user, string $guard): void
    {
        $this->resolvedUsers[$guard] ??= $user;
    }

    /** @return array<string, Authenticatable> every user signed in for this request, by guard */
    public function users(): array
    {
        return $this->resolvedUsers;
    }

    public function guardName(): ?string
    {
        return $this->resolvedGuard;
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

    public function user(): ?Authenticatable
    {
        return $this->resolvedUser;
    }
}
