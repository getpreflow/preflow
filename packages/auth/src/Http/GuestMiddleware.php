<?php

declare(strict_types=1);

namespace Preflow\Auth\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\AuthManager;
use Preflow\Core\Context\Contexts;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sends users of one guard who are signed in away (a login or sign-up page). Under a role view
 * (view as role) it asks no guard, so the session the request carries (an editor's) never
 * decides: the role view's user (AuthManager::user(), signed in as its roles) does, and it is
 * sent away like any signed-in user.
 */
final class GuestMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthManager $authManager,
        private readonly string $redirectTo = '/',
        /** the guard whose signed-in users are sent away; null is the default guard */
        private readonly ?string $guard = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // A guest of this guard: someone signed in with another guard may still sign in here.
        $name = $this->guard ?? $this->authManager->defaultGuardName();
        if (Contexts::current()->viewer->isSynthetic()) {
            $user = $this->authManager->user();
        } else {
            $user = $this->authManager->guardName() === $name ? $this->authManager->user() : null;
            $user ??= $this->authManager->guard($name)->user($request);
        }
        if ($user !== null) {
            return new Response(302, ['Location' => $this->redirectTo]);
        }
        return $handler->handle($request);
    }
}
