<?php

declare(strict_types=1);

namespace Preflow\Auth\Http;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Finds who is signed in for this request (never a 401: AuthMiddleware decides
 * that). Guards are asked in order, default first; the first user wins. A user
 * already on the request (set in-process, e.g. by tests) is taken as is.
 */
final class ResolveUserMiddleware implements MiddlewareInterface
{
    /** @param array<string, GuardInterface> $guards name => guard, in the order to ask */
    public function __construct(
        private readonly AuthManager $authManager,
        private readonly array $guards,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(Authenticatable::class);
        $name = null;
        if ($user instanceof Authenticatable) {
            $preset = $request->getAttribute(AuthManager::GUARD_ATTRIBUTE);
            $name = is_string($preset) ? $preset : $this->authManager->defaultGuardName();
        } else {
            $user = null;
            foreach ($this->guards as $guardName => $guard) {
                if (($user = $guard->user($request)) !== null) {
                    $name = (string) $guardName;
                    break;
                }
            }
        }

        if ($user !== null && $name !== null) {
            $this->authManager->setUser($user, $name);
            $request = $request
                ->withAttribute(Authenticatable::class, $user)
                ->withAttribute(AuthManager::GUARD_ATTRIBUTE, $name);
        }

        return $handler->handle($request);
    }
}
