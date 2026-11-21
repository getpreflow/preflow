<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * A role to view the site as (view as role): a population ('staff' or 'member') and its role
 * names, sorted and each once, so two views of the same roles are equal. Since 0.81.0 it may name
 * the guard the person viewing as it is signed in with (an editor's staff guard): a role view of
 * that guard's own population then counts as signed in with that guard only, never with another
 * guard of the same population the editor has no account in (auth's AuthMiddleware).
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
     * @param string|null $guard the guard of the person viewing as these roles (since 0.81.0); null: not known
     * @throws \InvalidArgumentException for an empty name, a population with ':' or a role with ',', an empty guard
     */
    public function __construct(public string $population, array $roles, public ?string $guard = null)
    {
        if ($guard === '') {
            throw new \InvalidArgumentException("A role view's guard is a name or null, got ''.");
        }
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

    /**
     * The same roles, viewed by someone signed in with $guard.
     *
     * @since 0.81.0
     */
    public function by(?string $guard): self
    {
        return new self($this->population, $this->roles, $guard);
    }

    /** The identity a viewer has while viewing as these roles (the guard is not part of it). */
    public function identity(): SyntheticIdentity
    {
        return new SyntheticIdentity($this);
    }
}
