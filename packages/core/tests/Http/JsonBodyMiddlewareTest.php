<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Exceptions\HttpException;
use Preflow\Core\Http\JsonBodyMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class JsonBodyMiddlewareTest extends TestCase
{
    private function makeHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?array $parsedBody = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->parsedBody = $request->getParsedBody();
                return new Response(200);
            }
        };
    }

    public function test_json_body_is_parsed_when_content_type_is_application_json(): void
    {
        $factory = new Psr17Factory();
        $body = $factory->createStream('{"name":"Alice","age":30}');
        $request = $factory->createServerRequest('POST', '/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);

        $handler = $this->makeHandler();
        (new JsonBodyMiddleware())->process($request, $handler);

        $this->assertSame(['name' => 'Alice', 'age' => 30], $handler->parsedBody);
    }

    public function test_parsed_body_remains_null_for_non_json_content_type(): void
    {
        $factory = new Psr17Factory();
        $body = $factory->createStream('{"name":"Alice"}');
        $request = $factory->createServerRequest('POST', '/')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($body);

        $handler = $this->makeHandler();
        (new JsonBodyMiddleware())->process($request, $handler);

        $this->assertNull($handler->parsedBody);
    }

    public function test_malformed_json_body_is_a_400(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', '/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"a":'));

        try {
            (new JsonBodyMiddleware())->process($request, $this->makeHandler());
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame('The request body is not valid JSON.', $e->getMessage());
        }
    }

    public function test_empty_body_still_passes(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', '/')
            ->withHeader('Content-Type', 'application/json');

        $handler = $this->makeHandler();
        (new JsonBodyMiddleware())->process($request, $handler);

        $this->assertNull($handler->parsedBody);
    }

    public function test_json_scalar_body_passes_without_parsed_body(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', '/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('"x"'));

        $handler = $this->makeHandler();
        (new JsonBodyMiddleware())->process($request, $handler);

        $this->assertNull($handler->parsedBody);
    }
}
