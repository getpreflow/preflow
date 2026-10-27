<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Auth\Record\RoleField;
use Preflow\Data\DynamicRecord;

/** A member's roles field (pending, member or rejected), read by auth's rule (RoleField), as RecordUser reads it. */
final class MemberRoles
{
    /** @return list<string> trimmed, unique, non-empty, in the stored order; malformed entries dropped */
    public static function of(DynamicRecord $member): array
    {
        return self::normalize($member->get('roles'));
    }

    /** @return list<string> by auth's rule ({@see RoleField}), the one RecordUser and so MemberAccess read roles by */
    public static function normalize(mixed $roles): array
    {
        return RoleField::normalize($roles);
    }

    /**
     * Whether these roles admit a member: "member" without "rejected" or "pending" (either wins).
     * The one rule MemberState and MemberAccess read.
     *
     * @param list<string> $roles normalized
     */
    public static function admitted(array $roles): bool
    {
        return in_array('member', $roles, true) && !in_array('rejected', $roles, true) && !in_array('pending', $roles, true);
    }
}
