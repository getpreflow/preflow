<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Session;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Http\LocaleUrls;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\Http\Session\SessionMiddleware;
use Preflow\Testing\ArraySession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionMiddlewareTest extends TestCase
{
    private function createRequest(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/');
    }

    private function createHandler(?ServerRequestInterface &$captured = null): RequestHandlerInterface
    {
        return new class($captured) implements RequestHandlerInterface {
            public function __construct(private ?ServerRequestInterface &$captured) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                return new Response(200);
            }
        };
    }

    protected function tearDown(): void
    {
        Contexts::reset();
    }

    /**
     * A role view (view as role) has no session, whatever its purpose: the cookie the request
     * carries is the editor's, so the middleware never starts, ages, reads or closes that session,
     * and ofCurrentRender() is null inside, also when the frame was narrowed into the role view
     * after its session started.
     */
    public function test_a_role_view_never_touches_the_session_the_request_carries(): void
    {
        $site = new Site('https://example.test', ['en'], 'en', new LocaleUrls(['en'], 'en'));
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://example.test/')->withCookieParams(['sess' => 'editor-cookie']);
        foreach ([Purpose::Visit, Purpose::Fragment, Purpose::Preview] as $purpose) {
            $session = new SpySession();
            $middleware = new SessionMiddleware($session, null, 'sess');
            $ctx = RenderContext::fromRequest($request, $site, new SystemClock())->asVisitor()->asRole(new RoleView('member', ['gold']))->because($purpose);

            [$attached, $current] = Contexts::run($ctx, function () use ($middleware, $request): array {
                $captured = null;
                $current = 'unset';
                $middleware->process($request, new class($captured, $current) implements RequestHandlerInterface {
                    public function __construct(private ?ServerRequestInterface &$captured, private mixed &$current) {}

                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        $this->captured = $request;
                        $this->current = SessionMiddleware::ofCurrentRender();

                        return new Response(200);
                    }
                });

                return [$captured?->getAttribute(SessionInterface::class), $current];
            });

            $this->assertSame([], $session->calls, $purpose->value . ': the editor\'s session is never touched');
            $this->assertNull($attached, $purpose->value);
            $this->assertNull($current, $purpose->value);
        }

        // A frame whose request carries a session and is then narrowed into a role view reads none.
        $session = new SpySession();
        $visit = RenderContext::fromRequest($request->withAttribute(SessionInterface::class, $session), $site, new SystemClock());
        $seen = Contexts::run($visit, static function (): array {
            $before = SessionMiddleware::ofCurrentRender();
            Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->asRole(new RoleView('member', ['gold'])));

            return [$before, SessionMiddleware::ofCurrentRender()];
        });
        $this->assertSame([$session, null], $seen);
    }

    public function test_attaches_session_to_request_attribute(): void
    {
        $session = new ArraySession();
        $middleware = new SessionMiddleware($session);

        $captured = null;
        $handler = $this->createHandler($captured);

        $middleware->process($this->createRequest(), $handler);

        $this->assertNotNull($captured);
        $this->assertSame($session, $captured->getAttribute(SessionInterface::class));
    }

    public function test_starts_session(): void
    {
        $session = new ArraySession();
        $middleware = new SessionMiddleware($session);

        $this->assertFalse($session->isStarted());

        $middleware->process($this->createRequest(), $this->createHandler());

        $this->assertTrue($session->isStarted());
    }

    public function test_ages_flash_data_across_requests(): void
    {
        $session = new ArraySession();

        // First request: set a flash message
        $session->start();
        $session->flash('notice', 'Welcome!');

        // Flash is readable in the same request (in current)
        $this->assertSame('Welcome!', $session->getFlash('notice'));

        // Second request: middleware ages flash
        $middleware = new SessionMiddleware($session);
        $middleware->process($this->createRequest(), $this->createHandler());

        // Flash is still readable (moved to previous)
        $this->assertSame('Welcome!', $session->getFlash('notice'));

        // Third request: middleware ages flash again — previous is now cleared
        $middleware->process($this->createRequest(), $this->createHandler());

        // Flash is now gone
        $this->assertNull($session->getFlash('notice'));
    }
}

/** A session that records what was done with it. */
final class SpySession implements SessionInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function start(): void { $this->calls[] = 'start'; }
    public function getId(): string { return 'editor-session'; }
    public function regenerate(): void { $this->calls[] = 'regenerate'; }
    public function invalidate(): void { $this->calls[] = 'invalidate'; }
    public function get(string $key, mixed $default = null): mixed { $this->calls[] = 'get:' . $key; return 'editor-value'; }
    public function set(string $key, mixed $value): void { $this->calls[] = 'set:' . $key; }
    public function has(string $key): bool { $this->calls[] = 'has:' . $key; return true; }
    public function remove(string $key): void { $this->calls[] = 'remove:' . $key; }
    public function flash(string $key, mixed $value): void { $this->calls[] = 'flash:' . $key; }
    public function getFlash(string $key, mixed $default = null): mixed { $this->calls[] = 'getFlash:' . $key; return null; }
    public function isStarted(): bool { return true; }
    public function ageFlash(): void { $this->calls[] = 'ageFlash'; }
    public function close(): void { $this->calls[] = 'close'; }
}
