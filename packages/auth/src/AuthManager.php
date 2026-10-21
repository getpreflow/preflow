<?php

declare(strict_types=1);

namespace Preflow\Auth;

final class AuthManager
{
    /** Request attribute naming the guard that signed in the request's Authenticatable. */
    public const string GUARD_ATTRIBUTE = 'preflow.auth.guard';

    private ?Authenticatable $resolvedUser = null;

    private ?string $resolvedGuard = null;

    /** @param array<string, GuardInterface> $guards */
    public function __construct(
        private readonly array $guards,
        private readonly string $defaultGuard,
    ) {}

    public function guard(?string $name = null): GuardInterface
    {
        $name ??= $this->defaultGuard;
        if (!isset($this->guards[$name])) {
            throw new \RuntimeException("Auth guard [{$name}] is not configured.");
        }
        return $this->guards[$name];
    }

    /** The user signed in for this request, and the guard that signed them in. */
    public function setUser(?Authenticatable $user, ?string $guard = null): void
    {
        $this->resolvedUser = $user;
        $this->resolvedGuard = $user === null ? null : ($guard ?? $this->defaultGuard);
    }

    public function guardName(): ?string
    {
        return $this->resolvedGuard;
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
