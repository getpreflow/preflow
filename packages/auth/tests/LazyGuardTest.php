<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\Http\ResolveUserMiddleware;
use Preflow\Auth\LazyGuard;
use Preflow\Auth\LazyPasswordHasher;
use Preflow\Auth\NativePasswordHasher;
use Psr\Http\Message\ServerRequestInterface;

/** Guards of the app's own classes are built on first use (0.66), and handed out as themselves. */
final class LazyGuardTest extends TestCase
{
    private function lazy(int &$built): LazyGuard
    {
        return new LazyGuard('api', NobodyGuard::class, static function () use (&$built): NobodyGuard {
            $built++;

            return new NobodyGuard();
        });
    }

    public function test_the_manager_builds_it_once_on_first_use_and_hands_out_the_guard(): void
    {
        $built = 0;
        $auth = new AuthManager(['api' => $this->lazy($built)], 'api');

        $this->assertSame(0, $built);
        $this->assertNull($auth->tokenGuardName(), 'asking for a token guard builds nothing');
        $this->assertSame(0, $built);
        $this->assertInstanceOf(NobodyGuard::class, $auth->guard('api'));
        $this->assertSame($auth->guard(), $auth->guard('api'));
        $this->assertSame(1, $built);
    }

    public function test_the_middleware_and_the_manager_share_the_one_guard(): void
    {
        $built = 0;
        $lazy = $this->lazy($built);
        $auth = new AuthManager(['api' => $lazy], 'api');
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface
            {
                return new \Nyholm\Psr7\Response(204);
            }
        };

        (new ResolveUserMiddleware($auth, ['api' => $lazy]))->process((new Psr17Factory())->createServerRequest('GET', '/'), $handler);

        $this->assertSame(1, $built);
        $this->assertSame($lazy->built(), $auth->guard('api'));
        $this->assertSame(1, $built);
    }

    public function test_a_class_that_is_no_guard_is_refused_when_built(): void
    {
        $lazy = new LazyGuard('api', \stdClass::class, static fn (): object => new \stdClass());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Guard "api"');
        $lazy->resolve();
    }

    public function test_the_lazy_hasher_asks_on_each_use(): void
    {
        $inner = new NativePasswordHasher();
        $asked = 0;
        $hasher = new LazyPasswordHasher(static function () use ($inner, &$asked): NativePasswordHasher {
            $asked++;

            return $inner;
        });

        $this->assertSame(0, $asked);
        $this->assertTrue($hasher->verify('secret', $inner->hash('secret')));
        $this->assertSame(1, $asked);
        $this->assertSame($inner, $hasher->inner());
    }
}

final class NobodyGuard implements GuardInterface
{
    public function user(ServerRequestInterface $request): ?Authenticatable
    {
        return null;
    }

    public function validate(array $credentials): bool
    {
        return false;
    }

    public function login(Authenticatable $user, ServerRequestInterface $request): void {}

    public function logout(ServerRequestInterface $request): void {}
}
