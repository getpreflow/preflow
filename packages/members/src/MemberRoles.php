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
}
