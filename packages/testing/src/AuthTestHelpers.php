<?php

declare(strict_types=1);

namespace Preflow\Testing;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Psr\Http\Message\ServerRequestInterface;

trait AuthTestHelpers
{
    /**
     * Sign the user in for this request. Handled by the app, the user counts
     * everywhere: AuthMiddleware, auth_user(), can() (in the guard's population).
     *
     * @param string|null $guard the guard they signed in with; null is the default guard
     */
    protected function actingAs(
        Authenticatable $user,
        ServerRequestInterface $request,
        ?string $guard = null,
    ): ServerRequestInterface {
        $request = $request->withAttribute(Authenticatable::class, $user);

        return $guard !== null ? $request->withAttribute(AuthManager::GUARD_ATTRIBUTE, $guard) : $request;
    }
}
