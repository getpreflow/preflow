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
 * inside one) keeps it, and so does an internal render (the SEO audit, GEO, a
 * rebuild, verify, a preview: its caller chose the viewer): no guard is asked, so the session or remember-me cookie
 * the request carries never signs the real user in, and nothing is written or
 * queued; the request carries the role view's SyntheticUser.
 *
 * A session guard with a remember-me cookie signs its user back in from the
 * cookie when its session has nobody (every such guard, not only up to the
 * winner, so a member stays remembered beside a signed-in staff user). The
 * guards queue cookies (login, logout, rotation) only where the request has a
 * session. They queue into the render frame's own CookieQueue (the
 * application's follows the frame), so a render in its own frame inside this
 * request (the SEO audit, a preview) neither sees nor takes them. On a request
 * with a session this middleware sets what that queue holds when the handler
 * returns, the site's cookies included; without one it leaves the queue alone.
 * Since 0.81.1 core sets the rest (the Kernel applies the frame's queue to the
 * route's response and to the pipeline's answer, and to the error response
 * when the handler throws), so a cookie is set once and none is dropped: a
 * cookie queued before this middleware ran is no longer dropped. Since 0.82.0
 * it sets them only where the frame's response takes them
 * (CookieQueue::reachesResponse()); in a render's frame (a preview) it leaves
 * them to the frame, which settles them by its outbox policy when it closes.
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
        $ctx = Contexts::current();
        if ($ctx->viewer->isSynthetic() || $ctx->purpose->isInternal()) {
            // The caller of the render chose the viewer (a role view, or an internal render's
            // anonymous visitor): no guard is asked, whatever session the request carries.
            $user = $this->authManager->user();
            $request = $request->withAttribute(RenderContext::class, $ctx);

            return $handler->handle($user === null ? $request : $request
                ->withAttribute(Authenticatable::class, $user)
                ->withAttribute(AuthManager::GUARD_ATTRIBUTE, $this->authManager->guardName()));
        }

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

        // Nobody this time: the frame's viewer is anonymous, whatever it was before (since 0.82.0
        // a frame that is anonymous already keeps its context: nothing to derive).
        Contexts::narrow(static fn (RenderContext $ctx): RenderContext => match (true) {
            $name !== null => $ctx->as(AuthManager::viewerOf($users, $name)),
            $ctx->viewer->isAnonymous() => $ctx,
            default => $ctx->as(Viewer::anonymous()),
        });
        $request = $request->withAttribute(RenderContext::class, Contexts::current());
        if ($name !== null) {
            $request = $request
                ->withAttribute(Authenticatable::class, $users[$name])
                ->withAttribute(AuthManager::GUARD_ATTRIBUTE, $name);
        }

        $response = $handler->handle($request);

        // Without a session the guards queued nothing; what the site queued is core's to set.
        if ($request->getAttribute(SessionInterface::class) === null) {
            return $response;
        }
        foreach ($this->pullCookies() as $cookie) {
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
            // Only where the response takes them (since 0.82.0): a render's frame or one that
            // does not deliver settles them by its policy when it closes.
            if ($guard instanceof SessionGuard && $guard->cookiesReachResponse()) {
                array_push($cookies, ...$guard->pullCookies());
            }
        }

        return $cookies;
    }
}
