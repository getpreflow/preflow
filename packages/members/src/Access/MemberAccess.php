<?php

declare(strict_types=1);

namespace Preflow\Members\Access;

use Preflow\Auth\Access\AllowsRoles;
use Preflow\Auth\Access\ComparesRoles;
use Preflow\Auth\Access\ListsRoles;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\Authenticatable;
use Preflow\Members\MemberRoles;
use Preflow\Members\MembersConfig;

/**
 * Access without a permission package (rbac, roles): an admitted member (role "member") may use
 * the members area (members.access), and nothing more; pending and rejected members get
 * nothing, also when "member" is among their roles ({@see MemberRoles::admitted()}). Every other question goes to the resolver before it (auth's default: staff may do
 * everything). With rbac, rbac's resolver answers instead (MembersServiceProvider leaves it).
 * A role view (view as role) gets the same from its roles (allowsRoles()).
 */
final class MemberAccess implements PermissionResolver, ListsRoles, AllowsRoles, ComparesRoles
{
    public function __construct(
        private readonly PermissionResolver $fallback,
    ) {}

    public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
    {
        if ($population === 'members') {
            return $permission === MembersConfig::PERMISSION && MemberRoles::admitted($user->getRoles());
        }

        return $this->fallback->allows($user, $population, $permission, $subject);
    }

    /**
     * As allows() for a user with exactly $roles: members.access for admitted member roles, nothing
     * else for members; another population is the resolver's before it (nothing when it cannot
     * answer for roles).
     *
     * @since 0.81.0
     */
    public function allowsRoles(array $roles, string $population, string $permission, ?object $subject): bool
    {
        if ($population === 'members') {
            return $permission === MembersConfig::PERMISSION && MemberRoles::admitted(MemberRoles::normalize($roles));
        }

        return $this->fallback instanceof AllowsRoles && $this->fallback->allowsRoles($roles, $population, $permission, $subject);
    }

    /**
     * Members: admitted roles grant members.access and nothing else, which a user already has when
     * they are admitted themselves. Another population is the resolver's before it (ComparesRoles),
     * or, when it cannot compare, only roles the user holds themselves.
     *
     * @since 0.81.0
     */
    public function rolesWithin(array $roles, string $population, Authenticatable $user): bool
    {
        if ($population === 'members') {
            return !MemberRoles::admitted(MemberRoles::normalize($roles)) || MemberRoles::admitted($user->getRoles());
        }
        if ($this->fallback instanceof ComparesRoles) {
            return $this->fallback->rolesWithin($roles, $population, $user);
        }

        return array_diff($roles, array_map('strval', array_filter($user->getRoles(), static fn (mixed $role): bool => is_string($role) || is_int($role)))) === [];
    }

    /** Members have the one role "member"; another population's roles are the resolver's before it (none when it lists none). */
    public function roles(string $population): array
    {
        if ($population === 'members') {
            return ['member'];
        }

        return $this->fallback instanceof ListsRoles ? $this->fallback->roles($population) : [];
    }
}
