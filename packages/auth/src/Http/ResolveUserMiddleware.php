<?php

declare(strict_types=1);

namespace Preflow\Auth\Http;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\SessionGuard;
use Preflow\Core\Http\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Finds who is signed in for this request (never a 401: AuthMiddleware decides
 * that). Guards are asked in order, default first; the first user wins for can(), though every signed-in guard's user is kept for canAny(). A user
 * already on the request (set in-process, e.g. by tests) is taken as is.
 *
 * A session guard with a remember-me cookie signs its user back in from the
 * cookie when its session has nobody (every such guard, not only up to the
 * winner, so a member stays remembered beside a signed-in staff user). Cookies
 * the guards queue (login, logout, rotation) go on the response, only where the
 * request has a session: public pages never get a Set-Cookie. When the handler
 * throws, the Kernel applies the shared CookieQueue to the error response.
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
        // Cookies left from a request that never reached the end (long-running workers) are dropped.
        $this->pullCookies();

        $user = $request->getAttribute(Authenticatable::class);
        $name = null;
        $others = [];
        if ($user instanceof Authenticatable) {
            $preset = $request->getAttribute(AuthManager::GUARD_ATTRIBUTE);
            $name = is_string($preset) ? $preset : $this->authManager->defaultGuardName();
        } else {
            $user = null;
            foreach ($this->guards as $guardName => $guard) {
                $found = $guard->user($request);
                if ($found === null && $guard instanceof SessionGuard) {
                    $found = $guard->restore($request);
                }
                if ($found === null) {
                    continue;
                }
                if ($user === null) {
                    $user = $found;
                    $name = (string) $guardName;
                } else {
                    $others[(string) $guardName] = $found;
                }
            }
        }

        if ($user === null || $name === null) {
            // Nobody this time: never keep the previous request's user (tests, long-running workers).
            $this->authManager->setUser(null);
        } else {
            $this->authManager->setUser($user, $name);
            foreach ($others as $otherName => $other) {
                $this->authManager->addUser($other, $otherName);
            }
            $request = $request
                ->withAttribute(Authenticatable::class, $user)
                ->withAttribute(AuthManager::GUARD_ATTRIBUTE, $name);
        }

        $response = $handler->handle($request);

        $cookies = $this->pullCookies();
        if ($request->getAttribute(SessionInterface::class) === null) {
            return $response;
        }
        foreach ($cookies as $cookie) {
            $response = $response->withAddedHeader('Set-Cookie', $cookie);
        }

        return $response;
    }

    /** @return list<string> every session guard's queued Set-Cookie values */
    private function pullCookies(): array
    {
        $cookies = [];
        foreach ($this->guards as $guard) {
            if ($guard instanceof SessionGuard) {
                array_push($cookies, ...$guard->pullCookies());
            }
        }

        return $cookies;
    }
}
