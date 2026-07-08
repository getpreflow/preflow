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
        $user = $this->auth->guard()->user($request);
        if ($user === null) {
            return new Response(302, ['Location' => $this->prefix . '/login']);
        }
        $this->auth->setUser($user);
        $request = $request->withAttribute(Authenticatable::class, $user);
        return $handler->handle($request);
    }
}
