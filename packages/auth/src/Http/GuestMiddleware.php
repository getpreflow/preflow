<?php

declare(strict_types=1);

namespace Preflow\Auth\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\AuthManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class GuestMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthManager $authManager,
        private readonly string $redirectTo = '/',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->authManager->guard()->user($request);
        if ($user !== null) {
            return new Response(302, ['Location' => $this->redirectTo]);
        }
        return $handler->handle($request);
    }
}
