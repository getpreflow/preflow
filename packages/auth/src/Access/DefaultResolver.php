<?php

declare(strict_types=1);

namespace Preflow\Auth\Access;

use Preflow\Auth\Authenticatable;

/** Without a permission package: staff may do everything, members nothing (fail closed). */
final class DefaultResolver implements PermissionResolver
{
    public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
    {
        return $population === 'staff';
    }
}
