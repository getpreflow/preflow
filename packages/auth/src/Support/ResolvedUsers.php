<?php

declare(strict_types=1);

namespace Preflow\Auth\Support;

use Preflow\Auth\Authenticatable;

/**
 * The users AuthManager looked up for one render frame (a frame slot), by guard and id: a
 * restored viewer's bare ids are looked up once per frame, not on every user() call.
 *
 * @internal AuthManager
 * @since 0.81.0
 */
final class ResolvedUsers
{
    /** @var array<string, ?Authenticatable> */
    private array $users = [];

    /** @param \Closure(): ?Authenticatable $find */
    public function remember(string $guard, string $id, \Closure $find): ?Authenticatable
    {
        $key = $guard . "\0" . $id;
        if (!array_key_exists($key, $this->users)) {
            $this->users[$key] = $find();
        }

        return $this->users[$key];
    }
}
