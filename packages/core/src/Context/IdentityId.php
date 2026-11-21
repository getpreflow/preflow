<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

use Preflow\Core\Identity;

/**
 * An identity reduced to its id: what a serialized Viewer brings back instead of the user
 * record it held (which may carry a password hash), so primary() and for() of a restored
 * context return it. It says only which id a guard had signed in, not that the account still
 * exists or what it may do: never treat it as a user. auth's AuthManager looks the user up again
 * through the guard's provider (by guard and id) and treats the viewer as anonymous when that
 * finds nobody; other code reads getAuthId() and does the same.
 *
 * @since 0.81.0
 */
final readonly class IdentityId implements Identity
{
    public function __construct(public string $id) {}

    public function getAuthId(): string
    {
        return $this->id;
    }
}
