<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\Http\AuthMiddleware;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Core\Exceptions\UnauthorizedHttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddlewareTest extends TestCase
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
            private ?ServerRequestInterface $capturedRequest = null;

            public function __construct(private readonly int $status) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->capturedRequest = $request;
                return new Response($this->status);
            }

            public function getCapturedRequest(): ?ServerRequestInterface
            {
                return $this->capturedRequest;
            }
        };
    }

    public function test_passes_authenticated_user(): void
    {
        $user = new TestUser();
        $manager = $this->createManager($user);
        $middleware = new AuthMiddleware($manager);

        $handler = $this->createHandler();
        $request = $this->createRequest();
        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $capturedRequest = $handler->getCapturedRequest();
        $this->assertNotNull($capturedRequest);
        $this->assertSame($user, $capturedRequest->getAttribute(Authenticatable::class));
    }

    public function test_sets_user_on_auth_manager(): void
    {
        $user = new TestUser();
        $manager = $this->createManager($user);
        $middleware = new AuthMiddleware($manager);

        $middleware->process($this->createRequest(), $this->createHandler());

        $this->assertSame($user, $manager->user());
    }

    public function test_throws_401_when_not_authenticated(): void
    {
        $manager = $this->createManager(null);
        $middleware = new AuthMiddleware($manager);

        $this->expectException(UnauthorizedHttpException::class);
        $middleware->process($this->createRequest(), $this->createHandler());
    }
}
