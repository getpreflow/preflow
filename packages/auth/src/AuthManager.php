<?php

declare(strict_types=1);

namespace Preflow\Auth;

final class AuthManager
{
    private ?Authenticatable $resolvedUser = null;

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

    public function setUser(?Authenticatable $user): void
    {
        $this->resolvedUser = $user;
    }

    public function user(): ?Authenticatable
    {
        return $this->resolvedUser;
    }
}
