<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Data\DynamicRecord;
use Preflow\Rbac\RoleList;

/** Where a member stands, derived from the record: roles first, then the confirmed email. */
enum MemberState: string
{
    case Unconfirmed = 'unconfirmed';
    case Waiting = 'waiting';
    case Member = 'member';
    case Declined = 'declined';

    public static function fromRecord(DynamicRecord $m): self
    {
        $roles = RoleList::normalize($m->get('roles'));
        if (in_array('rejected', $roles, true)) {
            return self::Declined;
        }
        if (in_array('member', $roles, true)) {
            return self::Member;
        }
        $confirmed = $m->get('email_confirmed_at');
        if ($confirmed === null || $confirmed === '') {
            return self::Unconfirmed;
        }

        return self::Waiting;
    }

    public function label(): string
    {
        return $this->value;
    }
}
