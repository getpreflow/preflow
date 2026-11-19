<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * A role to view the site as (view as role): a population ('staff' or 'member') and its role
 * names, sorted and each once, so two views of the same roles are equal.
 *
 * @since 0.81.0
 */
final readonly class RoleView
{
    /** @var list<string> sorted, each once */
    public array $roles;

    /**
     * @param string $population 'staff' or 'member'
     * @param list<string> $roles
     * @throws \InvalidArgumentException for an empty name, a population with ':' or a role with ','
     */
    public function __construct(public string $population, array $roles)
    {
        if ($population === '' || str_contains($population, ':')) {
            throw new \InvalidArgumentException("A role view's population must be a name without ':', got '{$population}'.");
        }
        $roles = array_values(array_unique(array_map('strval', $roles)));
        foreach ($roles as $role) {
            if ($role === '' || str_contains($role, ',')) {
                throw new \InvalidArgumentException("A role name must not be empty or contain ',', got '{$role}'.");
            }
        }
        sort($roles, SORT_STRING);
        $this->roles = $roles;
    }

    /** The identity a viewer has while viewing as these roles. */
    public function identity(): SyntheticIdentity
    {
        return new SyntheticIdentity($this);
    }
}
