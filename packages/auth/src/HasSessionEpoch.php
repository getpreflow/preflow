<?php

declare(strict_types=1);

namespace Preflow\Auth;

/**
 * A user whose sessions can be ended all at once: a SessionGuard keeps the epoch at login and
 * signs a session out once the user's epoch has moved on (a password change or reset bumps it).
 * A session that has no epoch stored (signed in before) counts as epoch "0".
 */
interface HasSessionEpoch
{
    public function getSessionEpoch(): string;
}
