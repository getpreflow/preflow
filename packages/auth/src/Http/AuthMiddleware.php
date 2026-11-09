<?php

declare(strict_types=1);

namespace Preflow\Auth\Http;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Core\Exceptions\UnauthorizedHttpException;
use Preflow\Core\Http\Api\ApiConfig;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    /**
     * @param string|null $guard the guard whose users may pass; null is the default guard
     * @param ApiConfig|null $api tells browsers from API clients; without it nobody is redirected and signed-out requests get 401
     */
    public function __construct(
        private readonly AuthManager $authManager,
        private readonly ?string $guard = null,
        private readonly ?ApiConfig $api = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only this guard's users pass: a signed-in member is no staff user, and vice versa.
        $name = $this->guard ?? $this->authManager->defaultGuardName();
        $user = $this->authManager->guardName() === $name ? $this->authManager->user() : null;
        $user ??= $this->authManager->guard($name)->user($request);
        if ($user === null) {
            $login = $this->api === null ? null : $this->authManager->loginPath($name);
            if ($login !== null) {
                if ($request->getHeaderLine('HX-Request') === 'true') {
                    // htmx would swap a redirected login page into a fragment; ask it to navigate instead.
                    throw new UnauthorizedHttpException(headers: ['HX-Redirect' => $login]);
                }
                if (in_array($request->getMethod(), ['GET', 'HEAD'], true) && !$this->api->wantsJson($request)) {
                    // Path and query only: never an absolute URL, so `next` cannot point off-site.
                    $uri = $request->getUri();
                    $next = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
                    return new Response(302, ['Location' => $login . '?next=' . rawurlencode($next)]);
                }
            }
            throw new UnauthorizedHttpException();
        }
        if ($this->authManager->user() !== $user) {
            $this->authManager->setUser($user, $name);
        }
        $request = $request->withAttribute(Authenticatable::class, $user);
        return $handler->handle($request);
    }
}
