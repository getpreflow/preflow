<?php

declare(strict_types=1);

namespace Preflow\Auth\Http;

use Preflow\Auth\Access\Access;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Exceptions\UnauthorizedHttpException;
use Preflow\Core\Http\Api\ApiConfig;
use Preflow\Core\Http\Hypermedia\HypermediaProtocols;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets only users of one guard through (401, or the guard's login for browsers). The user passes
 * as the render frame's viewer: the frame is narrowed to them (RenderContext::as()) unless they
 * are its primary user already, and the request carries the context and the user. A frame that
 * renders as a role view (view as role) is never narrowed and no guard is asked: it passes as its
 * SyntheticUser when it counts as signed in with this guard, its population being the guard's
 * (Access, since 0.81.0, as GuestMiddleware decides), and is treated as signed out otherwise
 * (also without Access).
 */
final class AuthMiddleware implements MiddlewareInterface
{
    /**
     * @param string|null $guard the guard whose users may pass; null is the default guard
     * @param ApiConfig|null $api tells browsers from API clients; without it nobody is redirected and signed-out requests get 401
     * @param HypermediaProtocols|null $hypermedia the clients whose fragment requests are told to navigate to the
     *        login; without it, the ones core put on the request (an app's own AuthMiddleware gets them too)
     */
    public function __construct(
        private readonly AuthManager $authManager,
        private readonly ?string $guard = null,
        private readonly ?ApiConfig $api = null,
        private readonly ?HypermediaProtocols $hypermedia = null,
        /** since 0.81.0: the guards' populations, which a role view is compared with */
        private readonly ?Access $access = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only this guard's users pass: a signed-in member is no staff user, and vice versa.
        $name = $this->guard ?? $this->authManager->defaultGuardName();
        $user = null;
        // A role view (view as role) is never replaced: no guard is asked, so the request's own
        // session cannot sign the real user in under it. It passes a guard of its own population.
        $roleView = Contexts::current()->viewer->roleView;
        if ($roleView !== null) {
            if ($this->access !== null && Access::populationOfRoleView($roleView) === $this->access->populationOf($name)) {
                $request = $request
                    ->withAttribute(Authenticatable::class, $this->authManager->user())
                    ->withAttribute(RenderContext::class, Contexts::current());

                return $handler->handle($request);
            }
        } else {
            $user = $this->authManager->guardName() === $name ? $this->authManager->user() : null;
            $user ??= $this->authManager->guard($name)->user($request);
            if ($user !== null && AuthManager::isReserved($user)) {
                $user = null;
            }
        }
        if ($user === null) {
            $login = $this->api === null ? null : $this->authManager->loginPath($name);
            if ($login !== null) {
                // A hypermedia client would swap a redirected login page into a fragment; ask it to
                // navigate instead (htmx: HX-Redirect). Told in headers only, the 401 page is the error page.
                $hypermedia = $this->hypermedia ?? $request->getAttribute(HypermediaProtocols::class);
                $navigated = $hypermedia instanceof HypermediaProtocols ? $hypermedia->navigate($request, new Response(401), $login) : null;
                if ($navigated !== null) {
                    if ((string) $navigated->getBody() !== '') {
                        return $navigated;
                    }
                    $headers = [];
                    foreach (array_keys($navigated->getHeaders()) as $header) {
                        $headers[$header] = $navigated->getHeaderLine($header);
                    }
                    throw new UnauthorizedHttpException(headers: $headers);
                }
                if (in_array($request->getMethod(), ['GET', 'HEAD'], true) && !$this->api->wantsJson($request)) {
                    // Path and query only: never an absolute URL, so `next` cannot point off-site.
                    $uri = $request->getUri();
                    $next = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
                    $glue = str_contains($login, '?') ? '&' : '?';
                    return new Response(302, ['Location' => $login . $glue . 'next=' . rawurlencode($next)]);
                }
            }
            throw new UnauthorizedHttpException();
        }
        if ($this->authManager->user() !== $user) {
            // The guard's user is the frame's only one from here on, as setUser() made it before 0.81.
            Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->as(AuthManager::viewerOf([$name => $user], $name)));
        }
        $request = $request
            ->withAttribute(Authenticatable::class, $user)
            ->withAttribute(RenderContext::class, Contexts::current());
        return $handler->handle($request);
    }
}
