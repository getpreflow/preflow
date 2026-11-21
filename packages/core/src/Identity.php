<?php

declare(strict_types=1);

namespace Preflow\Core;

/**
 * Who someone is, as far as core needs to know: an id. auth's Authenticatable extends it, so
 * every signed-in user is one; core reads only the id (the page cache's signed-in check, the
 * render context's memo key), never a user record.
 *
 * @since 0.81.0
 */
interface Identity
{
    /** The id, unique within its guard. Ids starting with 'preflow:synthetic:' are reserved (SyntheticIdentity). */
    public function getAuthId(): string;
}
