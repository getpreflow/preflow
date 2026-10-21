<?php

declare(strict_types=1);

namespace Preflow\Auth\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\AuthManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

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
        $user = $this->authManager->guardName() === $name ? $this->authManager->user() : null;
        $user ??= $this->authManager->guard($name)->user($request);
        if ($user !== null) {
            return new Response(302, ['Location' => $this->redirectTo]);
        }
        return $handler->handle($request);
    }
}
