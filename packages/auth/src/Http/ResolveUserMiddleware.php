<?php

declare(strict_types=1);

namespace Preflow\Auth\Http;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\LazyGuard;
use Preflow\Auth\SessionGuard;
use Preflow\Auth\SyntheticUser;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Viewer;
use Preflow\Core\Http\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Finds who is signed in for this request (never a 401: AuthMiddleware decides
 * that) and narrows the request's render frame to them (Contexts::narrow() with
 * RenderContext::as()): AuthManager reads the frame's viewer. The request goes on
 * with the narrowed context as its RenderContext attribute and the primary user
 * as its Authenticatable. Guards are asked in order, default first; the first
 * user wins for can(), though every signed-in guard's user is kept for canAny().
 * A user already on the request (set in-process, e.g. by tests) is taken as is
 * (a SyntheticUser as its role view). Any other user whose id is reserved for role views counts as nobody, whichever guard
 * returned it.
 *
 * A frame that renders as a role view (view as role, and a request asked for
 * inside one) keeps it: no guard is asked, so the session or remember-me cookie
 * the request carries never signs the real user in, and nothing is written or
 * queued; the request carries the role view's SyntheticUser.
 *
 * A session guard with a remember-me cookie signs its user back in from the
 * cookie when its session has nobody (every such guard, not only up to the
 * winner, so a member stays remembered beside a signed-in staff user). Cookies
 * the guards queue (login, logout, rotation) go on the response, only where the
 * request has a session: public pages never get a Set-Cookie. The guards queue
 * into the render frame's own CookieQueue (the application's follows the frame),
 * so a render in its own frame inside this request (the SEO audit, a preview)
 * neither sees nor takes them. When the handler throws, the Kernel applies the
 * frame's CookieQueue to the error response. Cookies queued in the frame before
 * this middleware are dropped, and ones queued after the handler returned are
 * never set (the frame drops them at its end), as before 0.81.
 */
final class ResolveUserMiddleware implements MiddlewareInterface
{
    /** @param array<string, GuardInterface|LazyGuard> $guards name => guard, in the order to ask; a LazyGuard is built when first asked (since 0.66) */
    public function __construct(
        private readonly AuthManager $authManager,
        private readonly array $guards,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $viewer = Contexts::current()->viewer;
        if ($viewer->isSynthetic()) {
            $user = $this->authManager->user();

            return $handler->handle($request
                ->withAttribute(Authenticatable::class, $user)
                ->withAttribute(AuthManager::GUARD_ATTRIBUTE, $this->authManager->guardName())
                ->withAttribute(RenderContext::class, Contexts::current()));
        }

        // What this frame queued before the guards ran is not this middleware's to set.
        $this->pullCookies();

        $users = [];
        $name = null;
        $preset = $request->getAttribute(Authenticatable::class);
        if ($preset instanceof SyntheticUser || ($preset instanceof Authenticatable && !AuthManager::isReserved($preset))) {
            $guard = $request->getAttribute(AuthManager::GUARD_ATTRIBUTE);
            $name = is_string($guard) ? $guard : $this->authManager->defaultGuardName();
            $users[$name] = $preset;
        } elseif (!$preset instanceof Authenticatable) {
            foreach ($this->guards as $guardName => $guard) {
                $guard = LazyGuard::of($guard);
                $found = $guard->user($request);
                if ($found === null && $guard instanceof SessionGuard) {
                    $found = $guard->restore($request);
                }
                if ($found === null || AuthManager::isReserved($found)) {
                    continue;
                }
                $name ??= (string) $guardName;
                $users[(string) $guardName] = $found;
            }
        }

        // Nobody this time: the frame's viewer is anonymous, whatever it was before.
        Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->as(
            $name === null ? Viewer::anonymous() : AuthManager::viewerOf($users, $name),
        ));
        $request = $request->withAttribute(RenderContext::class, Contexts::current());
        if ($name !== null) {
            $request = $request
                ->withAttribute(Authenticatable::class, $users[$name])
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

    /** @return list<string> every session guard's queued Set-Cookie values (the current frame's, for the application's guards) */
    private function pullCookies(): array
    {
        $cookies = [];
        foreach ($this->guards as $guard) {
            // A guard not built yet has queued nothing.
            $guard = $guard instanceof LazyGuard ? $guard->built() : $guard;
            if ($guard instanceof SessionGuard) {
                array_push($cookies, ...$guard->pullCookies());
            }
        }

        return $cookies;
    }
}
