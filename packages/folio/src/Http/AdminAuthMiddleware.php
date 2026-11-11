<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gates the Folio admin: unauthenticated requests are redirected (302) to the
 * login page instead of throwing 401 (the framework AuthMiddleware behavior).
 */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly string $prefix,
        private readonly AdminNav $nav,
        /** Folio's staff guard (StaffGuard); null: the default guard */
        private readonly ?string $guard = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only Folio's staff guard signs into the admin; a user resolved for it already (or set in
        // tests) counts. A user of another guard on the request (an employer, a member) does not.
        $guard = $this->guard ?? $this->auth->defaultGuardName();
        $preset = $request->getAttribute(Authenticatable::class);
        $presetGuard = $request->getAttribute(AuthManager::GUARD_ATTRIBUTE) ?? $this->auth->defaultGuardName();
        $user = $preset instanceof Authenticatable && $presetGuard === $guard
            ? $preset
            : $this->auth->guard($guard)->user($request);
        if ($user === null) {
            // htmx requests (autosave, block picker) would follow a 302 and swap the
            // login page into the editor: tell htmx to navigate instead. Folio's admin ships its
            // own htmx (@folio/htmx.js), whatever the app's hypermedia driver is, so its header is read here.
            if ($request->getHeaderLine('HX-Request') === 'true') {
                return new Response(401, ['HX-Redirect' => $this->prefix . '/login']);
            }
            return new Response(302, ['Location' => $this->prefix . '/login']);
        }
        $this->auth->setUser($user, $guard);
        $this->nav->setCurrentPath($request->getUri()->getPath());
        $request = $request->withAttribute(Authenticatable::class, $user);
        return $handler->handle($request);
    }
}
