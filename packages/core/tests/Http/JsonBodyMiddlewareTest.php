<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
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

    public function test_parsed_body_remains_null_for_invalid_json(): void
    {
        $factory = new Psr17Factory();
        $body = $factory->createStream('{not valid json}');
        $request = $factory->createServerRequest('POST', '/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);

        $handler = $this->makeHandler();
        (new JsonBodyMiddleware())->process($request, $handler);

        $this->assertNull($handler->parsedBody);
    }
}
