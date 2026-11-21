<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Preflow\Core\Identity;

/**
 * A user a guard signs in. Since 0.81 it is core's Identity (getAuthId() was already here), so the
 * render context's viewer holds it. Ids starting with 'preflow:synthetic:' are reserved for role
 * views (SyntheticUser): the guards never resolve them.
 */
interface Authenticatable extends Identity
{
    public function getAuthId(): string;
    public function getPasswordHash(): string;
    public function getRoles(): array;
    public function hasRole(string $role): bool;
}
