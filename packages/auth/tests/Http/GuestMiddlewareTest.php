<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\Http\GuestMiddleware;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class GuestMiddlewareTest extends TestCase
{
    private function createGuard(?Authenticatable $user): GuardInterface
    {
        return new class($user) implements GuardInterface {
            public function __construct(private readonly ?Authenticatable $user) {}

            public function user(ServerRequestInterface $request): ?Authenticatable
            {
                return $this->user;
            }

            public function validate(array $credentials): bool
            {
                return false;
            }

            public function login(Authenticatable $user, ServerRequestInterface $request): void {}

            public function logout(ServerRequestInterface $request): void {}
        };
    }

    private function createManager(?Authenticatable $user): AuthManager
    {
        return new AuthManager(['web' => $this->createGuard($user)], 'web');
    }

    private function createRequest(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', 'http://localhost/');
    }

    private function createHandler(int $status = 200): RequestHandlerInterface
    {
        return new class($status) implements RequestHandlerInterface {
            public function __construct(private readonly int $status) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->status);
            }
        };
    }

    public function test_passes_unauthenticated_users(): void
    {
        $manager = $this->createManager(null);
        $middleware = new GuestMiddleware($manager);

        $response = $middleware->process($this->createRequest(), $this->createHandler(200));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_redirects_authenticated_users(): void
    {
        $user = new TestUser();
        $manager = $this->createManager($user);
        $middleware = new GuestMiddleware($manager);

        $response = $middleware->process($this->createRequest(), $this->createHandler());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaderLine('Location'));
    }

    public function test_redirects_to_custom_path(): void
    {
        $user = new TestUser();
        $manager = $this->createManager($user);
        $middleware = new GuestMiddleware($manager, '/dashboard');

        $response = $middleware->process($this->createRequest(), $this->createHandler());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/dashboard', $response->getHeaderLine('Location'));
    }
}
