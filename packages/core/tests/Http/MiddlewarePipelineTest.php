<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Core\Http\MiddlewarePipeline;

class AddHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $value,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $response = $handler->handle($request);
        return $response->withHeader($this->name, $this->value);
    }
}

class ModifyRequestMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $request = $request->withAttribute('modified', true);
        return $handler->handle($request);
    }
}

class ShortCircuitMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        return new Response(403, [], 'Forbidden');
    }
}

final class MiddlewarePipelineTest extends TestCase
{
    private function createRequest(string $method = 'GET', string $uri = '/'): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        return $factory->createServerRequest($method, $uri);
    }

    public function test_empty_pipeline_calls_core_handler(): void
    {
        $pipeline = new MiddlewarePipeline();
        $request = $this->createRequest();

        $response = $pipeline->process($request, function (ServerRequestInterface $req): ResponseInterface {
            return new Response(200, [], 'OK');
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', (string) $response->getBody());
    }

    public function test_middleware_can_modify_response(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new AddHeaderMiddleware('X-Custom', 'test-value'));

        $response = $pipeline->process($this->createRequest(), function ($req) {
            return new Response(200);
        });

        $this->assertSame('test-value', $response->getHeaderLine('X-Custom'));
    }

    public function test_middleware_executes_in_order(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new AddHeaderMiddleware('X-First', '1'));
        $pipeline->pipe(new AddHeaderMiddleware('X-Second', '2'));

        $response = $pipeline->process($this->createRequest(), function ($req) {
            return new Response(200);
        });

        $this->assertSame('1', $response->getHeaderLine('X-First'));
        $this->assertSame('2', $response->getHeaderLine('X-Second'));
    }

    public function test_middleware_can_modify_request(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new ModifyRequestMiddleware());

        $capturedRequest = null;
        $pipeline->process($this->createRequest(), function (ServerRequestInterface $req) use (&$capturedRequest) {
            $capturedRequest = $req;
            return new Response(200);
        });

        $this->assertTrue($capturedRequest->getAttribute('modified'));
    }

    public function test_middleware_can_short_circuit(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new ShortCircuitMiddleware());
        $pipeline->pipe(new AddHeaderMiddleware('X-After', 'should-not-appear'));

        $coreReached = false;
        $response = $pipeline->process($this->createRequest(), function ($req) use (&$coreReached) {
            $coreReached = true;
            return new Response(200);
        });

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($coreReached);
        $this->assertFalse($response->hasHeader('X-After'));
    }

    public function test_multiple_middleware_stack(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new AddHeaderMiddleware('X-Outer', 'outer'));
        $pipeline->pipe(new ModifyRequestMiddleware());
        $pipeline->pipe(new AddHeaderMiddleware('X-Inner', 'inner'));

        $capturedRequest = null;
        $response = $pipeline->process($this->createRequest(), function (ServerRequestInterface $req) use (&$capturedRequest) {
            $capturedRequest = $req;
            return new Response(200);
        });

        $this->assertSame('outer', $response->getHeaderLine('X-Outer'));
        $this->assertSame('inner', $response->getHeaderLine('X-Inner'));
        $this->assertTrue($capturedRequest->getAttribute('modified'));
    }
}
