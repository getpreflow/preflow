<?php

declare(strict_types=1);

namespace Preflow\Ai\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Messages\CacheControlEphemeral;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\Message;
use Anthropic\Messages\OutputConfig;
use Anthropic\Messages\TextBlockParam;
use Anthropic\RequestOptions;
use Preflow\Ai\AiClient;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiRequestFailed;
use Preflow\Ai\AiResponse;
use Preflow\Ai\AiUnavailable;
use Preflow\Ai\StopReason;
use Preflow\Ai\TokenUsage;
use Psr\Http\Client\ClientInterface;

/**
 * Claude through the official SDK. Retries on rate limits, overload and connection errors
 * are the SDK's own; this class only maps the request and the outcome.
 */
final class ClaudeClient implements AiClient
{
    /** Statuses that mean the key or the account cannot be used, so retrying the next request is pointless. */
    private const UNAVAILABLE_STATUSES = [401, 402, 403];

    public function __construct(
        private readonly Client $sdk,
        private readonly string $model = 'claude-sonnet-5-5',
    ) {}

    /** @param ClientInterface|null $http PSR-18 client; null lets the SDK discover one */
    public static function fromKey(string $apiKey, string $model, ?ClientInterface $http = null): self
    {
        $options = $http === null ? null : RequestOptions::with(transporter: $http);

        return new self(new Client(apiKey: $apiKey, requestOptions: $options), $model);
    }

    public function complete(AiRequest $request): AiResponse
    {
        try {
            $message = $this->sdk->messages->create(
                maxTokens: $request->maxOutputTokens,
                messages: [['role' => 'user', 'content' => $request->prompt]],
                model: $this->model,
                outputConfig: $request->schema === null
                    ? null
                    : OutputConfig::with(format: JSONOutputFormat::with(schema: $request->schema)),
                system: $this->system($request),
            );
        } catch (APIStatusException $e) {
            throw $this->statusError($e);
        } catch (APIException $e) {
            // Connection errors and timeouts, after the SDK's own retries. The SDK's own message
            // is only a generic description, so the transport's message is preferred.
            $detail = trim($e->getPrevious()?->getMessage() ?? '');
            throw new AiRequestFailed('Claude request failed: ' . ($detail !== '' ? $detail : trim($e->getMessage())), 0, $e);
        }

        return $this->response($message);
    }

    public function provider(): string
    {
        return 'claude';
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * The tuning is the same for every request of a batch, so it goes into one cached
     * system block: later requests read the prefix from the cache at a fraction of the price.
     * Claude takes no sampling settings, so the request's temperature is not sent.
     *
     * @return list<TextBlockParam>|null
     */
    private function system(AiRequest $request): ?array
    {
        if ($request->tuning->isEmpty()) {
            return null;
        }

        return [TextBlockParam::with(text: $request->tuning->system(), cacheControl: CacheControlEphemeral::with())];
    }

    private function response(Message $message): AiResponse
    {
        // Anthropic's input_tokens excludes cache reads and cache creation; TokenUsage::input means all input,
        // so they are added back. Cache creation is folded into input at the normal price (an approximation).
        $cacheRead = $message->usage->cacheReadInputTokens ?? 0;
        $usage = new TokenUsage(
            $message->usage->inputTokens + $cacheRead + ($message->usage->cacheCreationInputTokens ?? 0),
            $message->usage->outputTokens,
            $cacheRead,
        );

        $stop = match ($message->stopReason) {
            'refusal' => StopReason::Refused,
            // A cut-off answer is not the JSON that was asked for, so its text is dropped
            'max_tokens', 'model_context_window_exceeded' => StopReason::Truncated,
            default => StopReason::Complete,
        };

        if ($stop !== StopReason::Complete) {
            $detail = $stop === StopReason::Refused ? $message->stopDetails?->category : null;

            return new AiResponse(null, $stop, $usage, 'claude', $this->model, $detail);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new AiResponse($text, StopReason::Complete, $usage, 'claude', $this->model);
    }

    private function statusError(APIStatusException $e): \RuntimeException
    {
        $body = $e->body;
        $detail = is_array($body) && is_array($body['error'] ?? null) && is_string($body['error']['message'] ?? null)
            ? $body['error']['message']
            : (is_string($body) && $body !== '' ? $body : 'no detail');
        $message = 'Claude returned HTTP ' . $e->status . ': ' . $detail;

        // An empty prepaid balance comes back as a plain 400
        if (in_array($e->status, self::UNAVAILABLE_STATUSES, true)
            || ($e->status === 400 && str_contains(strtolower($detail), 'credit balance'))
        ) {
            return new AiUnavailable($message, (int) $e->status, $e);
        }

        return new AiRequestFailed($message, (int) $e->status, $e);
    }
}
