<?php

declare(strict_types=1);

namespace Preflow\Auth\Access;

use Preflow\Auth\Authenticatable;

/**
 * A PermissionResolver that can say whether a set of roles grants nothing more than a user has:
 * Access::mayViewAs() asks it before an editor may view the site as a staff role (view as role),
 * so nobody sees the site with more rights than their own. The resolver answers by its own rules,
 * its subject checks included: "nothing more" must hold for every subject, so a resolver whose
 * grants depend on the subject answers false where it cannot tell. Without it, Access offers only
 * the staff roles the user holds themselves.
 *
 * @since 0.81.0
 */
interface ComparesRoles
{
    /**
     * Whether someone holding exactly $roles in $population may do nothing, on any subject, that
     * $user may not do in that population.
     *
     * @param list<string> $roles
     * @param 'staff'|'members' $population
     */
    public function rolesWithin(array $roles, string $population, Authenticatable $user): bool;
}
