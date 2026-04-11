<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Debug\DebugCollector;
use Preflow\DevTools\Http\DebugToolbarMiddleware;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class DebugToolbarMiddlewareTest extends TestCase
{
    private function makeHandler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    public function test_injects_toolbar_into_html_response(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);
        $body = '<html><body><p>Hello</p></body></html>';
        $response = new Response(200, ['Content-Type' => 'text/html'], $body);
        $handler = $this->makeHandler($response);
        $result = $middleware->process(new ServerRequest('GET', '/'), $handler);
        $html = (string) $result->getBody();
        $this->assertStringContainsString('preflow-debug-toolbar', $html);
        $this->assertStringContainsString('</body>', $html);
    }

    public function test_does_not_inject_into_json_response(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);
        $body = '{"status":"ok"}';
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);
        $handler = $this->makeHandler($response);
        $result = $middleware->process(new ServerRequest('GET', '/api'), $handler);
        $html = (string) $result->getBody();
        $this->assertStringNotContainsString('preflow-debug-toolbar', $html);
        $this->assertSame($body, $html);
    }

    public function test_does_not_inject_without_body_tag(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);
        $body = '<p>Fragment without body tag</p>';
        $response = new Response(200, ['Content-Type' => 'text/html'], $body);
        $handler = $this->makeHandler($response);
        $result = $middleware->process(new ServerRequest('GET', '/'), $handler);
        $html = (string) $result->getBody();
        $this->assertStringNotContainsString('preflow-debug-toolbar', $html);
    }

    public function test_preserves_status_code(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);
        $body = '<html><body>Not found</body></html>';
        $response = new Response(404, ['Content-Type' => 'text/html'], $body);
        $handler = $this->makeHandler($response);
        $result = $middleware->process(new ServerRequest('GET', '/missing'), $handler);
        $this->assertSame(404, $result->getStatusCode());
    }

    public function test_preserves_headers(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);
        $body = '<html><body>Test</body></html>';
        $response = new Response(200, ['Content-Type' => 'text/html', 'X-Custom' => 'value'], $body);
        $handler = $this->makeHandler($response);
        $result = $middleware->process(new ServerRequest('GET', '/'), $handler);
        $this->assertSame('value', $result->getHeaderLine('X-Custom'));
    }
}
