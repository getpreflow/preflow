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

    private function jsonRequest(string $path, string $json, ?int $contentLength = null, bool $unknownSize = false): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $stream = $unknownSize
            ? new class($json) implements \Psr\Http\Message\StreamInterface {
                private int $pos = 0;
                public int $read = 0;
                public function __construct(private string $data) {}
                public function __toString(): string { $this->read += strlen($this->data); return $this->data; }
                public function close(): void {}
                public function detach() { return null; }
                public function getSize(): ?int { return null; }
                public function tell(): int { return $this->pos; }
                public function eof(): bool { return $this->pos >= strlen($this->data); }
                public function isSeekable(): bool { return false; }
                public function seek(int $offset, int $whence = SEEK_SET): void {}
                public function rewind(): void {}
                public function isWritable(): bool { return false; }
                public function write(string $string): int { return 0; }
                public function isReadable(): bool { return true; }
                public function read(int $length): string
                {
                    $chunk = substr($this->data, $this->pos, $length);
                    $this->pos += strlen($chunk);
                    $this->read += strlen($chunk);
                    return $chunk;
                }
                public function getContents(): string { return $this->read(PHP_INT_MAX); }
                public function getMetadata(?string $key = null) { return null; }
            }
            : $factory->createStream($json);
        $request = $factory->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
        if ($contentLength !== null) {
            $request = $request->withHeader('Content-Length', (string) $contentLength);
        }

        return $request;
    }

    private function statusOf(callable $run): ?int
    {
        try {
            $run();
        } catch (HttpException $e) {
            return $e->statusCode;
        }

        return null;
    }

    public function test_oversized_content_length_is_413_without_reading_the_body(): void
    {
        $request = $this->jsonRequest('/x', '{"a":1}', contentLength: 5000, unknownSize: true);
        $stream = $request->getBody();

        $status = $this->statusOf(fn () => (new JsonBodyMiddleware(1000))->process($request, $this->makeHandler()));

        $this->assertSame(413, $status);
        $this->assertSame(0, $stream->read, 'the body was not read');
    }

    public function test_body_without_content_length_past_the_limit_is_413(): void
    {
        $json = json_encode(['pad' => str_repeat('a', 2000)]);
        $request = $this->jsonRequest('/x', $json, unknownSize: true);

        $status = $this->statusOf(fn () => (new JsonBodyMiddleware(1000))->process($request, $this->makeHandler()));

        $this->assertSame(413, $status);
        $this->assertLessThanOrEqual(1001 + 8192, $request->getBody()->read, 'reading stopped soon after the limit');
    }

    public function test_body_exactly_at_the_limit_is_parsed(): void
    {
        $json = '{"a":"' . str_repeat('b', 20) . '"}';
        $handler = $this->makeHandler();

        (new JsonBodyMiddleware(strlen($json)))->process($this->jsonRequest('/x', $json, unknownSize: true), $handler);

        $this->assertSame(['a' => str_repeat('b', 20)], $handler->parsedBody);
    }

    public function test_without_a_limit_a_large_body_still_parses(): void
    {
        $json = json_encode(['pad' => str_repeat('a', 3_000_000)]);
        $handler = $this->makeHandler();

        (new JsonBodyMiddleware())->process($this->jsonRequest('/x', $json, strlen($json)), $handler);

        $this->assertSame(3_000_000, strlen($handler->parsedBody['pad']));
    }

    public function test_api_prefix_limit_wins_on_api_paths_and_app_limit_applies_elsewhere(): void
    {
        $api = new \Preflow\Core\Http\Api\ApiConfig(['/api'], maxBody: 100);
        $json = json_encode(['pad' => str_repeat('a', 500)]);
        $mw = new JsonBodyMiddleware(maxBody: 10_000, api: $api);

        $this->assertSame(413, $this->statusOf(fn () => $mw->process($this->jsonRequest('/api/x', $json, strlen($json)), $this->makeHandler())));

        $handler = $this->makeHandler();
        $mw->process($this->jsonRequest('/other', $json, strlen($json)), $handler);
        $this->assertNotNull($handler->parsedBody);

        $tight = new JsonBodyMiddleware(maxBody: 100, api: new \Preflow\Core\Http\Api\ApiConfig(['/api']));
        $this->assertSame(413, $this->statusOf(fn () => $tight->process($this->jsonRequest('/other', $json, strlen($json)), $this->makeHandler())), 'app-wide limit');
        $this->assertSame(413, $this->statusOf(fn () => $tight->process($this->jsonRequest('/api/x', $json, strlen($json)), $this->makeHandler())), 'API without max_body falls back to the app-wide limit');
    }

    public function test_the_limit_ignores_non_json_bodies(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', '/x')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Content-Length', '999999')
            ->withBody($factory->createStream('a=1'));

        $this->assertNull($this->statusOf(fn () => (new JsonBodyMiddleware(10))->process($request, $this->makeHandler())));
    }
}
