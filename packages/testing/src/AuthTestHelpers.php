<?php

declare(strict_types=1);

namespace Preflow\Testing;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Context\RenderContext;
use Psr\Http\Message\ServerRequestInterface;

trait AuthTestHelpers
{
    /**
     * Sign the user in for this request. Handled by the app, the user counts
     * everywhere: AuthMiddleware, auth_user(), can() (in the guard's population).
     *
     * Since 0.81 the request also carries the viewer of its context: the frame handle() opens
     * for it has the user as its viewer from the start (Application::withDerivedContext()),
     * before the first middleware runs. The identity middleware takes the user as it is (no
     * guard is asked, no cookie is set). The next request without actingAs() is nobody's again.
     * A request that renders as a role view (WebApplication::withContext() with asRole()) keeps
     * its role view, as AuthManager::setUser() does. It works with handle(); render() takes its
     * context from its caller, so the derivation is not used there.
     *
     * @param string|null $guard the guard they signed in with; null is the default guard
     */
    protected function actingAs(
        Authenticatable $user,
        ServerRequestInterface $request,
        ?string $guard = null,
    ): ServerRequestInterface {
        $request = $request->withAttribute(Authenticatable::class, $user);
        if ($guard !== null) {
            $request = $request->withAttribute(AuthManager::GUARD_ATTRIBUTE, $guard);
        }

        return Application::withDerivedContext(
            $request,
            static function (RenderContext $ctx, Container $container) use ($user, $guard): RenderContext {
                if ($ctx->viewer->isSynthetic()) {
                    // A role view stays one: nobody signs in over it.
                    return $ctx;
                }
                // An app without auth has no default guard: the user's identity is the viewer's all the same.
                $name = $guard ?? ($container->bound(AuthManager::class) ? $container->get(AuthManager::class)->defaultGuardName() : 'default');

                return $ctx->as(AuthManager::viewerOf([$name => $user], $name));
            },
        );
    }
}
