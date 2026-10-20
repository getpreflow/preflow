<?php

declare(strict_types=1);

namespace Preflow\Auth\Access;

use Preflow\Auth\Authenticatable;

/** Answers "may this user do X?" — simple roles, full RBAC, or the default. */
interface PermissionResolver
{
    /** @param 'staff'|'members' $population */
    public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool;
}
