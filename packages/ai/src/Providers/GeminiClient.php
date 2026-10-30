<?php

declare(strict_types=1);

namespace Preflow\Ai\Providers;

use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Ai\AiClient;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiRequestFailed;
use Preflow\Ai\AiResponse;
use Preflow\Ai\AiUnavailable;
use Preflow\Ai\StopReason;
use Preflow\Ai\TokenUsage;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Gemini over its REST API, so no Google SDK is needed. The key travels only in the
 * x-goog-api-key header: URLs and error messages end up in logs, headers do not.
 */
final class GeminiClient implements AiClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** Statuses that mean the key or the account cannot be used, so retrying the next request is pointless. */
    private const UNAVAILABLE_STATUSES = [401, 402, 403];

    /** finishReason values where Gemini withheld the answer. */
    private const BLOCKED_REASONS = ['RECITATION', 'SAFETY', 'BLOCKLIST', 'PROHIBITED_CONTENT'];

    /** Long enough for a rate-limit window to move on, short enough not to stall a batch. */
    private const RETRY_DELAY_SECONDS = 2;

    private const MAX_RAW_BODY = 200;

    private readonly ClientInterface $http;
    private readonly RequestFactoryInterface $requests;
    private readonly StreamFactoryInterface $streams;

    /** @var \Closure(int):void */
    private readonly \Closure $sleeper;

    /** @param (\Closure(int):void)|null $sleeper receives the seconds to wait before the retry; injectable so tests never sleep */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-2.5-flash',
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
        ?\Closure $sleeper = null,
    ) {
        $factory = new Psr17Factory();
        $this->http = $http ?? new Psr18Client();
        $this->requests = $requests ?? $factory;
        $this->streams = $streams ?? $factory;
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function complete(AiRequest $request): AiResponse
    {
        $payload = json_encode($this->body($request), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = $this->send($payload);
        if ($this->isRetryable($response->getStatusCode())) {
            ($this->sleeper)(self::RETRY_DELAY_SECONDS);
            $response = $this->send($payload);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->statusError($status, (string) $response->getBody());
        }

        return $this->response((string) $response->getBody());
    }

    public function provider(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->model;
    }

    /** @return array<string,mixed> */
    private function body(AiRequest $request): array
    {
        $config = ['maxOutputTokens' => $request->maxOutputTokens];
        if ($request->temperature !== null) {
            $config['temperature'] = $request->temperature;
        }
        if ($request->schema !== null) {
            $config['responseMimeType'] = 'application/json';
            $config['responseSchema'] = self::withoutAdditionalProperties($request->schema);
        }

        $body = [];
        if (!$request->tuning->isEmpty()) {
            $body['systemInstruction'] = ['parts' => [['text' => $request->tuning->system()]]];
        }
        $body['contents'] = [['parts' => [['text' => $request->prompt]]]];
        $body['generationConfig'] = $config;

        return $body;
    }

    /**
     * responseSchema is an OpenAPI subset that rejects additionalProperties, which Claude's
     * structured output requires, so callers send one schema and this strips the keyword.
     * Only schema nodes are walked: the key is kept when it names a property.
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private static function withoutAdditionalProperties(array $schema): array
    {
        unset($schema['additionalProperties']);

        foreach ($schema as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $schema[$key] = $key === 'properties'
                ? array_map(
                    static fn(mixed $property): mixed => is_array($property) ? self::withoutAdditionalProperties($property) : $property,
                    $value,
                )
                : (array_is_list($value)
                    ? array_map(static fn(mixed $item): mixed => is_array($item) ? self::withoutAdditionalProperties($item) : $item, $value)
                    : self::withoutAdditionalProperties($value));
        }

        return $schema;
    }

    private function send(string $payload): ResponseInterface
    {
        $http = $this->requests
            ->createRequest('POST', self::ENDPOINT . rawurlencode($this->model) . ':generateContent')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('x-goog-api-key', $this->apiKey)
            ->withBody($this->streams->createStream($payload));

        try {
            return $this->http->sendRequest($http);
        } catch (ClientExceptionInterface $e) {
            throw new AiRequestFailed('Gemini request failed: ' . trim($e->getMessage()), 0, $e);
        }
    }

    private function isRetryable(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function response(string $raw): AiResponse
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AiRequestFailed('Gemini returned an unreadable response: ' . self::excerpt($raw), 0, $e);
        }
        if (!is_array($data)) {
            throw new AiRequestFailed('Gemini returned an unreadable response: ' . self::excerpt($raw));
        }

        $meta = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [];
        $usage = new TokenUsage(
            (int) ($meta['promptTokenCount'] ?? 0),
            (int) ($meta['candidatesTokenCount'] ?? 0) + (int) ($meta['thoughtsTokenCount'] ?? 0),
            (int) ($meta['cachedContentTokenCount'] ?? 0),
        );

        $blockReason = $data['promptFeedback']['blockReason'] ?? null;
        $candidate = $data['candidates'][0] ?? null;
        if (!is_array($candidate)) {
            if (is_string($blockReason) && $blockReason !== '') {
                return new AiResponse(null, StopReason::Blocked, $usage, 'gemini', $this->model, $blockReason);
            }
            throw new AiRequestFailed('Gemini returned no candidates.');
        }

        $finish = is_string($candidate['finishReason'] ?? null) ? $candidate['finishReason'] : 'STOP';
        if (in_array($finish, self::BLOCKED_REASONS, true)) {
            return new AiResponse(null, StopReason::Blocked, $usage, 'gemini', $this->model, $finish);
        }
        // A cut-off answer is not the JSON that was asked for, so its text is dropped
        if ($finish === 'MAX_TOKENS') {
            return new AiResponse(null, StopReason::Truncated, $usage, 'gemini', $this->model);
        }
        if ($finish !== 'STOP') {
            throw new AiRequestFailed('Gemini stopped with an unexpected reason: ' . $finish);
        }

        $text = '';
        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
            if (is_array($part) && is_string($part['text'] ?? null) && empty($part['thought'])) {
                $text .= $part['text'];
            }
        }

        return new AiResponse($text, StopReason::Complete, $usage, 'gemini', $this->model);
    }

    private function statusError(int $status, string $body): \RuntimeException
    {
        $data = json_decode($body, true);
        $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : null;
        $detail = $error !== null && is_string($error['message'] ?? null) && $error['message'] !== ''
            ? self::excerpt($error['message'])
            : (trim($body) !== '' ? self::excerpt($body) : 'no detail');
        $message = 'Gemini returned HTTP ' . $status . ': ' . $detail;

        // An invalid key comes back as a plain 400
        if (in_array($status, self::UNAVAILABLE_STATUSES, true)
            || ($status === 400 && str_contains($body, 'API_KEY_INVALID'))
        ) {
            return new AiUnavailable($message, $status);
        }

        return new AiRequestFailed($message, $status);
    }

    private static function excerpt(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > self::MAX_RAW_BODY ? mb_substr($text, 0, self::MAX_RAW_BODY) . '...' : $text;
    }
}
