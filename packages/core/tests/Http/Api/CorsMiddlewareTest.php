<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Api;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Api\ApiConfig;
use Preflow\Core\Http\Api\CorsMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorsMiddlewareTest extends TestCase
{
    /** @param array<string, string> $headers */
    private function request(string $method, string $path, array $headers = []): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, 'https://api.test' . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function handler(?ResponseInterface $response = null): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ?ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response ?? throw new \LogicException('the handler must not be called');
            }
        };
    }

    private function cors(array $origins = ['https://app.test'], bool $credentials = false, array $expose = []): CorsMiddleware
    {
        return new CorsMiddleware(new ApiConfig(
            corsOrigins: $origins,
            corsMethods: ['GET', 'POST'],
            corsHeaders: ['Authorization', 'Content-Type'],
            corsExpose: $expose,
            corsMaxAge: 300,
            corsCredentials: $credentials,
        ));
    }

    public function test_preflight_from_allowed_origin_is_answered_without_handler(): void
    {
        $response = $this->cors(credentials: true)->process($this->request('OPTIONS', '/api/jobs', [
            'Origin' => 'https://app.test',
            'Access-Control-Request-Method' => 'POST',
        ]), $this->handler());

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertSame('Authorization, Content-Type', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertSame('300', $response->getHeaderLine('Access-Control-Max-Age'));
        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function test_preflight_without_credentials_sends_no_credentials_header(): void
    {
        $response = $this->cors()->process($this->request('OPTIONS', '/api/jobs', [
            'Origin' => 'https://app.test',
            'Access-Control-Request-Method' => 'POST',
        ]), $this->handler());

        $this->assertSame(204, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    public function test_preflight_from_other_origin_gets_no_cors_headers(): void
    {
        $response = $this->cors()->process($this->request('OPTIONS', '/api/jobs', [
            'Origin' => 'https://evil.test',
            'Access-Control-Request-Method' => 'POST',
        ]), $this->handler());

        $this->assertSame(204, $response->getStatusCode());
        foreach (array_keys($response->getHeaders()) as $name) {
            $this->assertStringStartsNotWith('access-control-', strtolower((string) $name));
        }
    }

    public function test_simple_request_gets_allow_origin_and_vary(): void
    {
        $response = $this->cors(credentials: true, expose: ['X-Total', 'Link'])->process(
            $this->request('GET', '/api/jobs', ['Origin' => 'https://app.test']),
            $this->handler(new Response(200, ['Vary' => 'Accept-Encoding'], '{"data":[]}')),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('Accept-Encoding, Origin', $response->getHeaderLine('Vary'));
        $this->assertSame('X-Total, Link', $response->getHeaderLine('Access-Control-Expose-Headers'));
        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        $this->assertSame('{"data":[]}', (string) $response->getBody());
    }

    public function test_simple_request_from_other_origin_is_untouched(): void
    {
        $response = $this->cors()->process(
            $this->request('GET', '/api/jobs', ['Origin' => 'https://evil.test']),
            $this->handler(new Response(200)),
        );

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function test_wildcard_origin_without_credentials_sends_star(): void
    {
        $response = $this->cors(origins: ['*'])->process(
            $this->request('GET', '/api/jobs', ['Origin' => 'https://anyone.test']),
            $this->handler(new Response(200)),
        );

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
        $this->assertFalse($response->hasHeader('Access-Control-Expose-Headers'));
    }

    public function test_non_api_path_is_untouched(): void
    {
        $original = new Response(200, ['Vary' => 'Accept-Encoding']);
        $response = $this->cors()->process(
            $this->request('GET', '/jobs', ['Origin' => 'https://app.test']),
            $this->handler($original),
        );

        $this->assertSame($original, $response);

        // A preflight there is not ours either: the handler answers it.
        $options = $this->cors()->process(
            $this->request('OPTIONS', '/jobs', ['Origin' => 'https://app.test', 'Access-Control-Request-Method' => 'POST']),
            $this->handler(new Response(405)),
        );
        $this->assertSame(405, $options->getStatusCode());
    }

    public function test_request_without_origin_is_untouched(): void
    {
        $original = new Response(200);
        $response = $this->cors()->process($this->request('GET', '/api/jobs'), $this->handler($original));

        $this->assertSame($original, $response);
    }

    public function test_decorate_adds_headers_to_an_error_response(): void
    {
        $response = $this->cors()->decorate(
            $this->request('POST', '/api/jobs', ['Origin' => 'https://app.test']),
            new Response(422, ['Content-Type' => 'application/json']),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function test_vary_origin_is_not_added_twice(): void
    {
        $response = $this->cors()->decorate(
            $this->request('GET', '/api/jobs', ['Origin' => 'https://app.test']),
            new Response(200, ['Vary' => 'origin']),
        );

        $this->assertSame('origin', $response->getHeaderLine('Vary'));
    }
}
