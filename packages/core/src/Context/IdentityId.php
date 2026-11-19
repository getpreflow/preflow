<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

use Preflow\Core\Identity;

/**
 * An identity reduced to its id: what a serialized Viewer brings back instead of the user
 * record it held (which may carry a password hash). Built by Viewer::__unserialize() only.
 *
 * @internal
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
