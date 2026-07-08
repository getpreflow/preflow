<?php

declare(strict_types=1);

namespace Preflow\Auth;

/**
 * Authenticatable backed by a plain config entry (no persistence). Used by
 * ConfigUserProvider for config-credential auth.
 */
final class ArrayUser implements Authenticatable
{
    /** @param string[] $roles */
    public function __construct(
        private readonly string $id,
        private readonly string $email,
        private readonly string $passwordHash,
        private readonly array $roles = [],
    ) {}

    public function getAuthId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    /** @return string[] */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
