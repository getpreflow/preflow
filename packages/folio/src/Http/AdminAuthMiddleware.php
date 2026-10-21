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
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Folio's guard is the default one; a user resolved for it already (or set in tests) counts.
        $preset = $request->getAttribute(Authenticatable::class);
        $presetGuard = $request->getAttribute(AuthManager::GUARD_ATTRIBUTE) ?? $this->auth->defaultGuardName();
        $user = $preset instanceof Authenticatable && $presetGuard === $this->auth->defaultGuardName()
            ? $preset
            : $this->auth->guard()->user($request);
        if ($user === null) {
            // htmx requests (autosave, block picker) would follow a 302 and swap the
            // login page into the editor: tell htmx to navigate instead.
            if ($request->getHeaderLine('HX-Request') === 'true') {
                return new Response(401, ['HX-Redirect' => $this->prefix . '/login']);
            }
            return new Response(302, ['Location' => $this->prefix . '/login']);
        }
        $this->auth->setUser($user, $this->auth->defaultGuardName());
        $request = $request->withAttribute(Authenticatable::class, $user);
        return $handler->handle($request);
    }
}
