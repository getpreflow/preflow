<?php

declare(strict_types=1);

namespace Preflow\Auth;

trait AuthenticatableTrait
{
    public function getAuthId(): string
    {
        return $this->uuid;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getRoles(): array
    {
        return $this->roles ?? [];
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles(), true);
    }
}
