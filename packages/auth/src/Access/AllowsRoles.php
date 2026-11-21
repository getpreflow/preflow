<?php

declare(strict_types=1);

namespace Preflow\Auth\Access;

/**
 * A PermissionResolver that can answer for a set of roles without a user: may someone holding
 * exactly $roles in $population do $permission (on $subject)? It reads the role definitions and
 * never an account. Access asks it for a role view (view as role, a SyntheticUser), so the answer
 * follows the resolver's own rules, its matching, its deny rules and its checks of the subject,
 * as allows() would for a user with exactly these roles. A resolver that does not implement it
 * grants a role view nothing.
 *
 * @since 0.81.0
 */
interface AllowsRoles
{
    /**
     * Whether $roles in $population allow $permission on $subject; never true where a user with
     * exactly these roles (and no account of their own: owning nothing) would be refused.
     *
     * @param list<string> $roles
     * @param 'staff'|'members' $population
     */
    public function allowsRoles(array $roles, string $population, string $permission, ?object $subject): bool;
}
