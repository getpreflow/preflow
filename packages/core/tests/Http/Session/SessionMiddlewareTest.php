<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Session;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
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
