<?php

declare(strict_types=1);

namespace Preflow\Auth\Access;

/**
 * A PermissionResolver that knows which roles exist (preflow/roles' config/roles.php, preflow/rbac's
 * role records), so tools can check a role name before they give it to an account
 * (Access::roles(), e.g. the starter's staff:create).
 */
interface ListsRoles
{
    /** @return list<string> the names of the roles defined for the population ('staff', 'members'), in their order */
    public function roles(string $population): array;
}
