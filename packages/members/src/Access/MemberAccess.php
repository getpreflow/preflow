<?php

declare(strict_types=1);

namespace Preflow\Members\Access;

use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\Authenticatable;
use Preflow\Members\MembersConfig;

/**
 * Access without a permission package (rbac, roles): an admitted member (role "member") may use
 * the members area (members.access), and nothing more; pending and rejected members get
 * nothing. Every other question goes to the resolver before it (auth's default: staff may do
 * everything). With rbac, rbac's resolver answers instead (MembersServiceProvider leaves it).
 */
final class MemberAccess implements PermissionResolver
{
    public function __construct(
        private readonly PermissionResolver $fallback,
    ) {}

    public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
    {
        if ($population === 'members') {
            return $permission === MembersConfig::PERMISSION && in_array('member', $user->getRoles(), true);
        }

        return $this->fallback->allows($user, $population, $permission, $subject);
    }
}
