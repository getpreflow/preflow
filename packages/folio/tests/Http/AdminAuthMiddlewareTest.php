<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Folio\Http\AdminAuthMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdminAuthMiddlewareTest extends TestCase
{
    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'OK');
            }
        };
    }

    private function guard(?Authenticatable $user): GuardInterface
    {
        return new class($user) implements GuardInterface {
            public function __construct(private ?Authenticatable $user) {}
            public function user(ServerRequestInterface $request): ?Authenticatable { return $this->user; }
            public function validate(array $credentials): bool { return false; }
            public function login(Authenticatable $user, ServerRequestInterface $request): void {}
            public function logout(ServerRequestInterface $request): void {}
        };
    }

    public function test_redirects_to_login_when_unauthenticated(): void
    {
        $am = new AuthManager(['session' => $this->guard(null)], 'session');
        $mw = new AdminAuthMiddleware($am, '/folio', new \Preflow\Folio\Http\AdminNav(new \Preflow\Folio\Content\TypeCatalog(sys_get_temp_dir() . '/folio_none'), static fn (): bool => true, '/folio'));
        $res = $mw->process((new Psr17Factory())->createServerRequest('GET', '/folio'), $this->handler());

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('Location'));
    }

    public function test_htmx_request_gets_401_with_hx_redirect(): void
    {
        $am = new AuthManager(['session' => $this->guard(null)], 'session');
        $mw = new AdminAuthMiddleware($am, '/folio', new \Preflow\Folio\Http\AdminNav(new \Preflow\Folio\Content\TypeCatalog(sys_get_temp_dir() . '/folio_none'), static fn (): bool => true, '/folio'));
        $res = $mw->process((new Psr17Factory())->createServerRequest('POST', '/folio/post/p1/autosave')->withHeader('HX-Request', 'true'), $this->handler());

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('HX-Redirect'));
    }

    public function test_passes_through_when_authenticated(): void
    {
        $user = new \Preflow\Auth\ArrayUser('a@b.test', 'a@b.test', 'H', ['admin']);
        $am = new AuthManager(['session' => $this->guard($user)], 'session');
        $mw = new AdminAuthMiddleware($am, '/folio', new \Preflow\Folio\Http\AdminNav(new \Preflow\Folio\Content\TypeCatalog(sys_get_temp_dir() . '/folio_none'), static fn (): bool => true, '/folio'));
        $res = $mw->process((new Psr17Factory())->createServerRequest('GET', '/folio'), $this->handler());

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame($user, $am->user());
    }
}
