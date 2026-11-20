<?php

declare(strict_types=1);

namespace Preflow\Auth\Access;

/**
 * A PermissionResolver that can say what a set of roles grants without a user: a pure function of
 * the roles and their population, which reads the role definitions and never an account. Access
 * asks it for a role view (view as role, a SyntheticUser): the role view may do exactly what its
 * roles grant. A resolver that does not implement it grants a role view nothing.
 *
 * @since 0.81.0
 */
interface GrantsForRoles
{
    /**
     * The permission patterns $roles grant in $population ("*", "page.*", "page.edit"), in role
     * order, each once; [] for roles that are not defined there. Never more than what a user with
     * exactly these roles would be allowed.
     *
     * @param list<string> $roles
     * @param 'staff'|'members' $population
     * @return list<string>
     */
    public function grantsFor(array $roles, string $population): array;
}
