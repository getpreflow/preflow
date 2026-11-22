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
        $res = \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), fn () => $mw->process((new Psr17Factory())->createServerRequest('GET', '/folio'), $this->handler()));

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('Location'));
    }

    public function test_htmx_request_gets_401_with_hx_redirect(): void
    {
        $am = new AuthManager(['session' => $this->guard(null)], 'session');
        $mw = new AdminAuthMiddleware($am, '/folio', new \Preflow\Folio\Http\AdminNav(new \Preflow\Folio\Content\TypeCatalog(sys_get_temp_dir() . '/folio_none'), static fn (): bool => true, '/folio'));
        $res = \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), fn () => $mw->process((new Psr17Factory())->createServerRequest('POST', '/folio/post/p1/autosave')->withHeader('HX-Request', 'true'), $this->handler()));

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('HX-Redirect'));
    }

    public function test_passes_through_when_authenticated(): void
    {
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function (): void {
            $user = new \Preflow\Auth\ArrayUser('a@b.test', 'a@b.test', 'H', ['admin']);
            $am = new AuthManager(['session' => $this->guard($user)], 'session');
            $mw = new AdminAuthMiddleware($am, '/folio', new \Preflow\Folio\Http\AdminNav(new \Preflow\Folio\Content\TypeCatalog(sys_get_temp_dir() . '/folio_none'), static fn (): bool => true, '/folio'));
            $res = $mw->process((new Psr17Factory())->createServerRequest('GET', '/folio'), $this->handler());

            $this->assertSame(200, $res->getStatusCode());
            $this->assertSame($user, $am->user());
        });
    }

    /** @since 0.84.0 a staff screen, or a part of one, is never stored by the browser (back/forward would show the bare part). */
    public function test_staff_screens_and_their_parts_are_not_stored(): void
    {
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function (): void {
            $user = new \Preflow\Auth\ArrayUser('a@b.test', 'a@b.test', 'H', ['admin']);
            $am = new AuthManager(['session' => $this->guard($user)], 'session');
            $mw = new AdminAuthMiddleware($am, '/folio', new \Preflow\Folio\Http\AdminNav(new \Preflow\Folio\Content\TypeCatalog(sys_get_temp_dir() . '/folio_none'), static fn (): bool => true, '/folio'));
            $factory = new Psr17Factory();

            $page = $mw->process($factory->createServerRequest('GET', '/folio'), $this->handler());
            $part = $mw->process($factory->createServerRequest('GET', '/folio?part=needs')->withHeader('HX-Request', 'true'), $this->handler());
            $this->assertSame('no-store', $page->getHeaderLine('Cache-Control'));
            $this->assertSame('no-store', $part->getHeaderLine('Cache-Control'));

            $own = new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200, ['Cache-Control' => 'private, max-age=60'], 'OK');
                }
            };
            $this->assertSame('private, max-age=60', $mw->process($factory->createServerRequest('GET', '/folio'), $own)->getHeaderLine('Cache-Control'), 'a screen that says its own caching keeps it');
        });
    }

    /** A screen and its ?part= answer share one URL: through the security headers both vary on the client's headers and neither is stored. */
    public function test_a_screen_and_its_part_both_vary_and_are_not_stored(): void
    {
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function (): void {
            $user = new \Preflow\Auth\ArrayUser('a@b.test', 'a@b.test', 'H', ['admin']);
            $am = new AuthManager(['session' => $this->guard($user)], 'session');
            $auth = new AdminAuthMiddleware($am, '/folio', new \Preflow\Folio\Http\AdminNav(new \Preflow\Folio\Content\TypeCatalog(sys_get_temp_dir() . '/folio_none'), static fn (): bool => true, '/folio'));
            $protocols = new \Preflow\Core\Http\Hypermedia\HypermediaProtocols();
            $protocols->add(new \Preflow\Htmx\HtmxDriver(new \Preflow\Htmx\ResponseHeaders()));
            $security = new \Preflow\Core\Http\SecurityHeadersMiddleware(static fn (): string => 'abc', [], false, $protocols);
            $screen = new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], 'x');
                }
            };
            // The kernel order: security headers outside, the staff gate inside.
            $inner = new class($auth, $screen) implements RequestHandlerInterface {
                public function __construct(private AdminAuthMiddleware $auth, private RequestHandlerInterface $next) {}
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->auth->process($request, $this->next);
                }
            };
            $factory = new Psr17Factory();
            foreach ([
                'page' => $factory->createServerRequest('GET', '/folio'),
                'part' => $factory->createServerRequest('GET', '/folio?part=needs')->withHeader('HX-Request', 'true'),
            ] as $label => $request) {
                $res = $security->process($request, $inner);
                $this->assertSame('no-store', $res->getHeaderLine('Cache-Control'), $label);
                $this->assertStringContainsString('HX-Request', $res->getHeaderLine('Vary'), $label);
            }
        });
    }
}
