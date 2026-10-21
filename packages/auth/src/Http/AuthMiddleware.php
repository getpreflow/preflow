<?php

declare(strict_types=1);

namespace Preflow\Auth\Http;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Core\Exceptions\UnauthorizedHttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    /** @param string|null $guard the guard whose users may pass; null is the default guard */
    public function __construct(
        private readonly AuthManager $authManager,
        private readonly ?string $guard = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only this guard's users pass: a signed-in member is no staff user, and vice versa.
        $name = $this->guard ?? $this->authManager->defaultGuardName();
        $user = $this->authManager->guardName() === $name ? $this->authManager->user() : null;
        $user ??= $this->authManager->guard($name)->user($request);
        if ($user === null) {
            throw new UnauthorizedHttpException();
        }
        if ($this->authManager->user() !== $user) {
            $this->authManager->setUser($user, $name);
        }
        $request = $request->withAttribute(Authenticatable::class, $user);
        return $handler->handle($request);
    }
}
