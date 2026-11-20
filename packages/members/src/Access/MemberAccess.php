<?php

declare(strict_types=1);

namespace Preflow\Members\Access;

use Preflow\Auth\Access\GrantsForRoles;
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
 * A role view (view as role) gets the same from its roles (grantsFor()).
 */
final class MemberAccess implements PermissionResolver, ListsRoles, GrantsForRoles
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
     * What a role view's roles grant: members.access for admitted member roles, else nothing; another
     * population's are the resolver's before it (nothing when it cannot say).
     *
     * @since 0.81.0
     */
    public function grantsFor(array $roles, string $population): array
    {
        if ($population === 'members') {
            return MemberRoles::admitted(MemberRoles::normalize($roles)) ? [MembersConfig::PERMISSION] : [];
        }

        return $this->fallback instanceof GrantsForRoles ? $this->fallback->grantsFor($roles, $population) : [];
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
