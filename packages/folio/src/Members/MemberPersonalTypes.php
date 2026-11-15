<?php

declare(strict_types=1);

namespace Preflow\Folio\Members;

use Preflow\Publishing\PersonalTypes;

/**
 * The members package's account type holds people: a staff delete of a member in Folio is
 * erasure (the activity log forgets the account and what went with it). @since 0.77
 */
final class MemberPersonalTypes implements PersonalTypes
{
    public function personal(string $type): bool
    {
        return class_exists(\Preflow\Members\MembersConfig::class) && $type === \Preflow\Members\MembersConfig::TYPE;
    }
}
