<?php

declare(strict_types=1);

namespace Preflow\Auth;

interface Authenticatable
{
    public function getAuthId(): string;
    public function getPasswordHash(): string;
    public function getRoles(): array;
    public function hasRole(string $role): bool;
}
