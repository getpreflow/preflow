<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests\Providers;

use Anthropic\Client;
use Anthropic\RequestOptions;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiRequestFailed;
use Preflow\Ai\AiUnavailable;
use Preflow\Ai\Providers\ClaudeClient;
use Preflow\Ai\StopReason;
use Preflow\Ai\Tuning;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Drives the real SDK through a recording PSR-18 stub, so the wire format is what is
 * checked, not our assumptions about the SDK.
 */
final class ClaudeClientTest extends TestCase
{
    private const KEY = 'sk-ant-test-secret-key';

    /** @var list<RequestInterface> */
    private array $sent = [];

    /** @param list<ResponseInterface> $responses */
    private function client(array $responses): ClaudeClient
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

        // R3: no retries, so error tests never sleep
        $sdk = new Client(apiKey: self::KEY, requestOptions: RequestOptions::with(maxRetries: 0, transporter: $http));

        return new ClaudeClient($sdk);
    }

    /** @param array<string,mixed> $overrides */
    private static function message(array $overrides = []): Response
    {
        $body = array_replace([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => '{"ok":true}']],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 30, 'cache_read_input_tokens' => 990, 'cache_creation_input_tokens' => 5],
        ], $overrides);

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private static function error(int $status, string $message, string $type = 'api_error'): Response
    {
        $body = ['type' => 'error', 'error' => ['type' => $type, 'message' => $message]];

        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function sentBody(): array
    {
        $this->assertCount(1, $this->sent);

        return json_decode((string) $this->sent[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_sends_model_max_tokens_system_with_cache_control_and_user_prompt(): void
    {
        $tuning = new Tuning(['Translate board game texts.', 'Keep mechanic terms in English.'], '{"text": "..."}');

        $this->client([self::message()])->complete(
            new AiRequest('translate', 'Hello world', $tuning, temperature: 0.2, maxOutputTokens: 1234),
        );

        $body = $this->sentBody();
        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertSame(1234, $body['max_tokens']);
        $this->assertSame([['role' => 'user', 'content' => 'Hello world']], $body['messages']);
        $this->assertSame(
            [['type' => 'text', 'text' => $tuning->system(), 'cache_control' => ['type' => 'ephemeral']]],
            $body['system'],
        );
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertArrayNotHasKey('output_config', $body);
        $this->assertStringEndsWith('/v1/messages', $this->sent[0]->getUri()->getPath());
        $this->assertSame('Bearer ' . self::KEY, $this->sent[0]->getHeaderLine('Authorization'));
    }

    public function test_omits_system_when_tuning_empty(): void
    {
        $this->client([self::message()])->complete(new AiRequest('translate', 'Hello'));

        $this->assertArrayNotHasKey('system', $this->sentBody());
    }

    public function test_schema_is_enforced_with_structured_output(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['ok' => ['type' => 'boolean']],
            'required' => ['ok'],
            'additionalProperties' => false,
        ];

        $this->client([self::message()])->complete(new AiRequest('translate', 'Hello', new Tuning(['Be brief.']), $schema));

        $body = $this->sentBody();
        $this->assertSame(['format' => ['type' => 'json_schema', 'schema' => $schema]], $body['output_config']);
        $this->assertSame('Be brief.', $body['system'][0]['text']);
    }

    public function test_maps_end_turn_text_and_usage_including_cache_reads(): void
    {
        $response = $this->client([self::message([
            'content' => [['type' => 'text', 'text' => '{"ok":'], ['type' => 'text', 'text' => 'true}']],
        ])])->complete(new AiRequest('translate', 'Hello'));

        $this->assertSame(StopReason::Complete, $response->stop);
        $this->assertSame('{"ok":true}', $response->text);
        $this->assertSame(['ok' => true], $response->json());
        $this->assertSame(1005, $response->usage->input); // 10 + 990 cache read + 5 cache creation
        $this->assertSame(30, $response->usage->output);
        $this->assertSame(990, $response->usage->cachedInput);
        $this->assertSame('claude', $response->provider);
        $this->assertSame('claude-sonnet-5-5', $response->model);
        $this->assertNull($response->detail);
    }

    public function test_max_tokens_is_truncated_with_null_text(): void
    {
        $response = $this->client([self::message([
            'content' => [['type' => 'text', 'text' => '{"ok":']],
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 9],
        ])])->complete(new AiRequest('translate', 'Hello'));

        $this->assertSame(StopReason::Truncated, $response->stop);
        $this->assertNull($response->text);
        $this->assertSame(0, $response->usage->cachedInput);
        $this->assertSame(9, $response->usage->output);
    }

    public function test_refusal_is_refused_with_category(): void
    {
        $response = $this->client([self::message([
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => null],
        ])])->complete(new AiRequest('translate', 'Hello'));

        $this->assertSame(StopReason::Refused, $response->stop);
        $this->assertNull($response->text);
        $this->assertSame('cyber', $response->detail);
    }

    /** @return iterable<string,array{Response}> */
    public static function unavailableResponses(): iterable
    {
        yield '401' => [self::error(401, 'invalid x-api-key', 'authentication_error')];
        yield '402' => [self::error(402, 'Payment required', 'billing_error')];
        yield '403' => [self::error(403, 'Forbidden', 'permission_error')];
        yield 'credit balance 400' => [self::error(
            400,
            'Your credit balance is too low to access the Anthropic API.',
            'invalid_request_error',
        )];
    }

    #[DataProvider('unavailableResponses')]
    public function test_401_402_403_and_credit_balance_400_throw_unavailable(Response $response): void
    {
        try {
            $this->client([$response])->complete(new AiRequest('translate', 'Hello'));
            $this->fail('expected AiUnavailable');
        } catch (AiUnavailable $e) {
            $this->assertStringStartsWith('Claude returned HTTP ' . $response->getStatusCode() . ': ', $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function test_other_400_throws_request_failed(): void
    {
        $this->expectException(AiRequestFailed::class);
        $this->expectExceptionMessage('Claude returned HTTP 400: max_tokens: too large');

        $this->client([self::error(400, 'max_tokens: too large', 'invalid_request_error')])
            ->complete(new AiRequest('translate', 'Hello'));
    }

    public function test_500_after_retries_throws_request_failed_without_key_in_message(): void
    {
        try {
            $this->client([self::error(500, 'Internal server error')])->complete(new AiRequest('translate', 'Hello'));
            $this->fail('expected AiRequestFailed');
        } catch (AiRequestFailed $e) {
            $this->assertSame('Claude returned HTTP 500: Internal server error', $e->getMessage());
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
        $client = new ClaudeClient(new Client(apiKey: self::KEY, requestOptions: RequestOptions::with(maxRetries: 0, transporter: $http)));

        $this->expectException(AiRequestFailed::class);
        $this->expectExceptionMessage('Claude request failed: Connection refused');
        $client->complete(new AiRequest('translate', 'Hello'));
    }

    public function test_from_key_uses_the_given_http_client_and_model(): void
    {
        $http = new class ($this->sent) implements ClientInterface {
            /** @param list<RequestInterface> $sent */
            public function __construct(private array &$sent) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;

                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
                    'content' => [['type' => 'text', 'text' => 'hi']], 'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ], JSON_THROW_ON_ERROR));
            }
        };

        $client = ClaudeClient::fromKey(self::KEY, 'claude-haiku-5-5', $http);
        $response = $client->complete(new AiRequest('translate', 'Hello'));

        $this->assertSame('claude', $client->provider());
        $this->assertSame('claude-haiku-5-5', $client->model());
        $this->assertSame('hi', $response->text);
        $this->assertSame('claude-haiku-5-5', $this->sentBody()['model']);
        $this->assertSame('Bearer ' . self::KEY, $this->sent[0]->getHeaderLine('Authorization'));
    }

    public function test_low_reasoning_sends_effort_low_beside_the_schema(): void
    {
        $schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'], 'additionalProperties' => false];

        $this->client([self::message()])->complete(new AiRequest('translate', 'Hello', schema: $schema, lowReasoning: true));

        $body = $this->sentBody();
        $this->assertSame(['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]], $body['output_config']);
        $this->assertArrayNotHasKey('thinking', $body, 'Sonnet 5.5 refuses thinking {type: disabled}; effort bounds it');
    }

    public function test_low_reasoning_without_a_schema(): void
    {
        $this->client([self::message()])->complete(new AiRequest('translate', 'Hello', lowReasoning: true));

        $this->assertSame(['effort' => 'low'], $this->sentBody()['output_config']);
    }

    public function test_from_key_gives_up_after_120_seconds_and_retries_once(): void
    {
        $options = ClaudeClient::requestOptions();
        $this->assertSame(120.0, $options->timeout);
        $this->assertSame(1, $options->maxRetries);
        $this->assertInstanceOf(\Symfony\Component\HttpClient\Psr18Client::class, $options->transporter, 'the transport enforces the timeout; the SDK does not');

        $failing = new class ($this->sent) implements ClientInterface {
            /** @param list<RequestInterface> $sent */
            public function __construct(private array &$sent) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;

                return new Response(529, ['Content-Type' => 'application/json', 'retry-after-ms' => '1'], '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}');
            }
        };
        try {
            ClaudeClient::fromKey(self::KEY, 'claude-sonnet-5-5', $failing)->complete(new AiRequest('translate', 'Hello'));
            $this->fail('expected AiRequestFailed');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString('HTTP 529', $e->getMessage());
        }
        $this->assertCount(2, $this->sent, 'one try and one retry');
    }

    public function test_the_key_is_a_sensitive_parameter(): void
    {
        $param = (new \ReflectionMethod(ClaudeClient::class, 'fromKey'))->getParameters()[0];

        $this->assertNotEmpty($param->getAttributes(\SensitiveParameter::class));
    }
}
