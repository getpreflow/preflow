<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests\Providers;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiRequestFailed;
use Preflow\Ai\AiUnavailable;
use Preflow\Ai\Providers\GeminiClient;
use Preflow\Ai\StopReason;
use Preflow\Ai\Tuning;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Drives the client through a recording PSR-18 stub, so the wire format is what is checked. */
final class GeminiClientTest extends TestCase
{
    private const KEY = 'AIza-test-secret-key';

    /** @var list<RequestInterface> */
    private array $sent = [];

    /** @var list<int> */
    private array $slept = [];

    /** @param list<ResponseInterface> $responses */
    private function client(array $responses): GeminiClient
    {
        $http = new class ($responses, $this->sent) implements ClientInterface {
            /** @param list<ResponseInterface> $responses @param list<RequestInterface> $sent */
            public function __construct(private array $responses, private array &$sent) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;

                return array_shift($this->responses) ?? throw new \LogicException('No canned response left.');
            }
        };

        return new GeminiClient(self::KEY, http: $http, sleeper: function (int $seconds): void {
            $this->slept[] = $seconds;
        });
    }

    /** @param array<string,mixed> $overrides */
    public static function reply(array $overrides = []): Response
    {
        $body = array_replace([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => '{"ok":true}']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 120, 'candidatesTokenCount' => 30, 'cachedContentTokenCount' => 100],
        ], $overrides);

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private static function error(int $status, string $message, string $reason = ''): Response
    {
        $error = ['code' => $status, 'message' => $message, 'status' => 'ERR'];
        if ($reason !== '') {
            $error['details'] = [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => $reason]];
        }

        return new Response($status, ['Content-Type' => 'application/json'], json_encode(['error' => $error], JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function sentBody(int $index = 0): array
    {
        return json_decode((string) $this->sent[$index]->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_request_url_header_body(): void
    {
        $tuning = new Tuning(['Translate board game texts.'], '{"text": "..."}');
        $schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']];

        $this->client([self::reply()])->complete(
            new AiRequest('translate', 'Hello world', $tuning, $schema, temperature: 0.2, maxOutputTokens: 1234),
        );

        $this->assertCount(1, $this->sent);
        $request = $this->sent[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
            (string) $request->getUri(),
        );
        $this->assertSame(self::KEY, $request->getHeaderLine('x-goog-api-key'));
        $this->assertStringNotContainsString(self::KEY, (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $this->assertSame([
            'systemInstruction' => ['parts' => [['text' => $tuning->system()]]],
            'contents' => [['parts' => [['text' => 'Hello world']]]],
            'generationConfig' => [
                'maxOutputTokens' => 1234,
                'temperature' => 0.2,
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ], $this->sentBody());
    }

    public function test_omits_system_temperature_and_schema_when_unset(): void
    {
        $this->client([self::reply()])->complete(new AiRequest('translate', 'Hello'));

        $body = $this->sentBody();
        $this->assertArrayNotHasKey('systemInstruction', $body);
        $this->assertSame(['maxOutputTokens' => 8000], $body['generationConfig']);
    }

    public function test_additional_properties_are_stripped_recursively_from_the_schema(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                // a property that is itself named additionalProperties must survive
                'additionalProperties' => ['type' => 'string'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['id' => ['type' => 'string']],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['items'],
        ];

        $this->client([self::reply()])->complete(new AiRequest('translate', 'Hello', schema: $schema));

        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'additionalProperties' => ['type' => 'string'],
                'items' => [
                    'type' => 'array',
                    'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
                ],
            ],
            'required' => ['items'],
        ], $this->sentBody()['generationConfig']['responseSchema']);
    }

    public function test_maps_text_and_usage(): void
    {
        $client = $this->client([self::reply()]);
        $response = $client->complete(new AiRequest('translate', 'Hello'));

        $this->assertSame(StopReason::Complete, $response->stop);
        $this->assertSame(['ok' => true], $response->json());
        $this->assertSame(120, $response->usage->input);
        $this->assertSame(30, $response->usage->output);
        $this->assertSame(100, $response->usage->cachedInput);
        $this->assertSame('gemini', $response->provider);
        $this->assertSame('gemini-2.5-flash', $response->model);
        $this->assertNull($response->detail);
        $this->assertSame('gemini', $client->provider());
        $this->assertSame('gemini-2.5-flash', $client->model());
    }

    public function test_usage_counts_thoughts_as_output(): void
    {
        $response = $this->client([self::reply([
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 30, 'thoughtsTokenCount' => 500],
        ])])->complete(new AiRequest('translate', 'Hello'));

        $this->assertSame(530, $response->usage->output);
        $this->assertSame(0, $response->usage->cachedInput);
    }

    public function test_thought_parts_are_not_part_of_the_text(): void
    {
        $response = $this->client([self::reply([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'thinking...', 'thought' => true], ['text' => '{"ok":'], ['text' => 'true}']]],
                'finishReason' => 'STOP',
            ]],
        ])])->complete(new AiRequest('translate', 'Hello'));

        $this->assertSame('{"ok":true}', $response->text);
    }

    /** @return iterable<string,array{array<string,mixed>,StopReason,?string}> */
    public static function finishReasons(): iterable
    {
        $candidate = static fn(string $reason): array => [
            'candidates' => [['content' => ['parts' => [['text' => '{"ok":']]], 'finishReason' => $reason]],
        ];

        yield 'STOP' => [$candidate('STOP'), StopReason::Complete, null];
        yield 'MAX_TOKENS' => [$candidate('MAX_TOKENS'), StopReason::Truncated, null];
        yield 'RECITATION' => [$candidate('RECITATION'), StopReason::Blocked, 'RECITATION'];
        yield 'SAFETY' => [$candidate('SAFETY'), StopReason::Blocked, 'SAFETY'];
        yield 'BLOCKLIST' => [$candidate('BLOCKLIST'), StopReason::Blocked, 'BLOCKLIST'];
        yield 'PROHIBITED_CONTENT' => [$candidate('PROHIBITED_CONTENT'), StopReason::Blocked, 'PROHIBITED_CONTENT'];
        yield 'prompt blocked' => [
            ['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT'], 'usageMetadata' => ['promptTokenCount' => 7]],
            StopReason::Blocked,
            'PROHIBITED_CONTENT',
        ];
    }

    /** @param array<string,mixed> $overrides */
    #[DataProvider('finishReasons')]
    public function test_finish_reasons_map(array $overrides, StopReason $stop, ?string $detail): void
    {
        $body = $overrides + ['usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 3]];
        $response = $this->client([new Response(200, [], json_encode($body, JSON_THROW_ON_ERROR))])
            ->complete(new AiRequest('translate', 'Hello'));

        $this->assertSame($stop, $response->stop);
        $this->assertSame($detail, $response->detail);
        // Anything but a complete answer drops the (possibly partial) text
        $this->assertSame($stop === StopReason::Complete ? '{"ok":' : null, $response->text);
        $this->assertSame(7, $response->usage->input);
    }

    /** @return iterable<string,array{Response}> */
    public static function unavailableResponses(): iterable
    {
        yield '401' => [self::error(401, 'Unauthorized')];
        yield '402' => [self::error(402, 'Payment required')];
        yield '403' => [self::error(403, 'Permission denied')];
        yield 'API_KEY_INVALID 400' => [self::error(400, 'API key not valid. Please pass a valid API key.', 'API_KEY_INVALID')];
    }

    #[DataProvider('unavailableResponses')]
    public function test_unavailable_statuses(Response $response): void
    {
        try {
            $this->client([$response])->complete(new AiRequest('translate', 'Hello'));
            $this->fail('expected AiUnavailable');
        } catch (AiUnavailable $e) {
            $this->assertStringStartsWith('Gemini returned HTTP ' . $response->getStatusCode() . ': ', $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertSame([], $this->slept);
        }
    }

    public function test_other_400_throws_request_failed_without_retry(): void
    {
        try {
            $this->client([self::error(400, 'Invalid JSON payload')])->complete(new AiRequest('translate', 'Hello'));
            $this->fail('expected AiRequestFailed');
        } catch (AiRequestFailed $e) {
            $this->assertSame('Gemini returned HTTP 400: Invalid JSON payload', $e->getMessage());
            $this->assertCount(1, $this->sent);
            $this->assertSame([], $this->slept);
        }
    }

    public function test_retries_once_on_429_then_fails(): void
    {
        try {
            $this->client([self::error(429, 'Quota exceeded'), self::error(429, 'Quota exceeded')])
                ->complete(new AiRequest('translate', 'Hello'));
            $this->fail('expected AiRequestFailed');
        } catch (AiRequestFailed $e) {
            $this->assertNotInstanceOf(AiUnavailable::class, $e);
            $this->assertSame('Gemini returned HTTP 429: Quota exceeded', $e->getMessage());
            $this->assertSame(429, $e->getCode());
            $this->assertCount(2, $this->sent);
            $this->assertSame([2], $this->slept);
        }
    }

    public function test_a_5xx_retry_can_succeed(): void
    {
        $response = $this->client([self::error(503, 'Overloaded'), self::reply()])->complete(new AiRequest('translate', 'Hello'));

        $this->assertSame(StopReason::Complete, $response->stop);
        $this->assertCount(2, $this->sent);
        $this->assertSame([2], $this->slept);
    }

    public function test_raw_error_body_is_truncated_and_key_free(): void
    {
        $raw = new Response(502, ['Content-Type' => 'text/html'], '<html>' . str_repeat('x', 1000) . '</html>');

        try {
            $this->client([$raw, new Response(502, [], '<html>' . str_repeat('x', 1000) . '</html>')])
                ->complete(new AiRequest('translate', 'Hello'));
            $this->fail('expected AiRequestFailed');
        } catch (AiRequestFailed $e) {
            $this->assertLessThan(260, strlen($e->getMessage()));
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function test_connection_error_throws_request_failed(): void
    {
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('Connection refused') extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
                    public function getRequest(): RequestInterface
                    {
                        throw new \LogicException('unused');
                    }
                };
            }
        };
        $client = new GeminiClient(self::KEY, http: $http);

        $this->expectException(AiRequestFailed::class);
        $this->expectExceptionMessage('Gemini request failed: Connection refused');
        $client->complete(new AiRequest('translate', 'Hello'));
    }

    public function test_custom_model_goes_into_the_url(): void
    {
        $http = new class ($this->sent) implements ClientInterface {
            /** @param list<RequestInterface> $sent */
            public function __construct(private array &$sent) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;

                return GeminiClientTest::reply();
            }
        };
        $client = new GeminiClient(self::KEY, 'gemini-2.5-pro', $http);
        $response = $client->complete(new AiRequest('translate', 'Hello'));

        $this->assertStringEndsWith('/models/gemini-2.5-pro:generateContent', (string) $this->sent[0]->getUri());
        $this->assertSame('gemini-2.5-pro', $response->model);
    }

    public function test_low_reasoning_turns_thinking_off(): void
    {
        $this->client([self::reply()])->complete(new AiRequest('translate', 'Hello', lowReasoning: true));

        $this->assertSame(['thinkingBudget' => 0], $this->sentBody()['generationConfig']['thinkingConfig']);
    }

    public function test_thinking_is_left_to_the_model_by_default(): void
    {
        $this->client([self::reply()])->complete(new AiRequest('translate', 'Hello'));

        $this->assertArrayNotHasKey('thinkingConfig', $this->sentBody()['generationConfig']);
    }

    public function test_the_key_is_a_sensitive_parameter(): void
    {
        $param = (new \ReflectionMethod(GeminiClient::class, '__construct'))->getParameters()[0];

        $this->assertSame('apiKey', $param->getName());
        $this->assertNotEmpty($param->getAttributes(\SensitiveParameter::class));
    }
}
