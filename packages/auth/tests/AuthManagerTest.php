<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Psr\Http\Message\ServerRequestInterface;

final class AuthManagerTest extends TestCase
{
    private function makeGuard(?Authenticatable $user = null): GuardInterface
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

    public function test_guard_returns_default_guard(): void
    {
        $guard = $this->makeGuard();
        $manager = new AuthManager(['web' => $guard], 'web');

        $this->assertSame($guard, $manager->guard());
    }

    public function test_guard_returns_named_guard(): void
    {
        $webGuard = $this->makeGuard();
        $apiGuard = $this->makeGuard();
        $manager = new AuthManager(['web' => $webGuard, 'api' => $apiGuard], 'web');

        $this->assertSame($apiGuard, $manager->guard('api'));
    }

    public function test_guard_throws_for_unknown_guard(): void
    {
        $manager = new AuthManager(['web' => $this->makeGuard()], 'web');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Auth guard [unknown] is not configured.');
        $manager->guard('unknown');
    }

    public function test_set_and_get_user(): void
    {
        $manager = new AuthManager(['web' => $this->makeGuard()], 'web');
        $user = new TestUser();

        $this->assertNull($manager->user());

        $manager->setUser($user);

        $this->assertSame($user, $manager->user());
    }
}
