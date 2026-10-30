<?php

declare(strict_types=1);

namespace Preflow\Ai;

/**
 * The connection test behind `ai:check` and the admin's Test button: one tiny request asking
 * for {"ok":true}, to verify key, model and network. It never throws for the expected failures
 * (AI off, provider error, unusable answer); the result says which one happened, so each caller
 * words it its own way. The key is never part of the result.
 */
final class AiCheck
{
    public function __construct(private readonly Ai $ai) {}

    public function run(): AiCheckResult
    {
        if (!$this->ai->enabled()) {
            return new AiCheckResult(AiCheckResult::OFF);
        }

        try {
            $response = $this->ai->client()->complete(new AiRequest(
                'ai-check',
                'Reply with exactly this JSON and nothing else: {"ok":true}',
                temperature: 0.0,
                maxOutputTokens: 200,
            ));
            $data = $response->json();
        } catch (AiUnavailable | AiRequestFailed | AiResponseInvalid $e) {
            return new AiCheckResult(AiCheckResult::FAILED, $e->getMessage());
        }

        $cost = $this->ai->pricing()->cost($response->model, $response->usage);
        $ok = is_array($data) && ($data['ok'] ?? null) === true;

        return new AiCheckResult($ok ? AiCheckResult::OK : AiCheckResult::UNEXPECTED, null, $response->usage, $cost);
    }
}
