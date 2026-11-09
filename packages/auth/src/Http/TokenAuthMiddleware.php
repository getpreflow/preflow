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

final class TokenAuthMiddleware implements MiddlewareInterface
{
    /** @param string|null $guard the token guard to use; null is auth.api_guard, else the first TokenGuard configured */
    public function __construct(
        private readonly AuthManager $authManager,
        private readonly ?string $guard = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $name = $this->authManager->tokenGuardName($this->guard);
        // Asked on the request itself, never AuthManager::user(): a session cookie must not pass a token route.
        $user = $name === null ? null : $this->authManager->guard($name)->user($request);
        if ($user === null) {
            throw new UnauthorizedHttpException('Unauthorized', null, ['WWW-Authenticate' => 'Bearer']);
        }
        $this->authManager->setUser($user, $name);

        return $handler->handle($request->withAttribute(Authenticatable::class, $user));
    }
}
