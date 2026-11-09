<?php

declare(strict_types=1);

namespace App\Middleware;

use Preflow\Auth\AuthManager;
use Preflow\Auth\Http\AuthMiddleware;
use Preflow\Core\Http\Api\ApiConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets only employers through. A visitor who is not signed in is sent to the guard's login page
 * (config/auth.php) with ?next= set. The guard's name is the only thing that differs between
 * projects, so the routes that use this class stay the same if the guard changes.
 */
final class EmployerAuth implements MiddlewareInterface
{
    private readonly AuthMiddleware $inner;

    public function __construct(AuthManager $auth, ApiConfig $api)
    {
        $this->inner = new AuthMiddleware($auth, 'employers', $api);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->inner->process($request, $handler);
    }
}
