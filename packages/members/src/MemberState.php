<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Data\DynamicRecord;

/** Where a member stands, derived from the record: roles first ("rejected" wins, then {@see MemberRoles::admitted()}), then the confirmed email. */
enum MemberState: string
{
    case Unconfirmed = 'unconfirmed';
    case Waiting = 'waiting';
    case Member = 'member';
    case Declined = 'declined';

    public static function fromRecord(DynamicRecord $m): self
    {
        $roles = MemberRoles::of($m);
        if (in_array('rejected', $roles, true)) {
            return self::Declined;
        }
        if (MemberRoles::admitted($roles)) {
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
