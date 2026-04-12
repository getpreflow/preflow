<?php

declare(strict_types=1);

namespace Preflow\Testing;

use Preflow\Auth\Authenticatable;
use Psr\Http\Message\ServerRequestInterface;

trait AuthTestHelpers
{
    /**
     * Set the authenticated user for the given request.
     */
    protected function actingAs(
        Authenticatable $user,
        ServerRequestInterface $request,
    ): ServerRequestInterface {
        return $request->withAttribute(Authenticatable::class, $user);
    }
}
