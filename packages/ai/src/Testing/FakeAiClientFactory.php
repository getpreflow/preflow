<?php

declare(strict_types=1);

namespace Preflow\Ai\Testing;

use Preflow\Ai\AiClient;
use Preflow\Ai\AiClientFactory;
use Preflow\Ai\AiResponse;
use Preflow\Ai\StopReason;
use Preflow\Ai\TokenUsage;

/**
 * config/ai.php `'provider' => 'fake'`: AI without a key and without cost, for your machine and
 * for tests. Every request gets the same answer: `options.fake.answer` (an array is sent as JSON,
 * a string as text; default {"ok":true}, which ai:check accepts), with `options.fake.usage`
 * ([input, output] tokens, default [10, 10]). The model is `models.fake`, default `fake-model`,
 * which the price table doesn't know, so its cost shows as unknown. Never on a live site: it
 * answers every request with the same text. For a script (answers in order, a function per
 * request), write a factory of your own that returns a FakeAiClient.
 *
 * @since 0.66
 */
final class FakeAiClientFactory implements AiClientFactory
{
    public function available(#[\SensitiveParameter] array $options): bool
    {
        return true;
    }

    public function create(#[\SensitiveParameter] array $options): AiClient
    {
        $model = is_string($options['model'] ?? null) && $options['model'] !== '' ? $options['model'] : 'fake-model';
        $answer = $options['answer'] ?? ['ok' => true];
        $usage = is_array($options['usage'] ?? null) ? array_values($options['usage']) : [10, 10];
        $tokens = new TokenUsage((int) ($usage[0] ?? 0), (int) ($usage[1] ?? 0));
        $text = is_string($answer) ? $answer : json_encode($answer, JSON_THROW_ON_ERROR);

        return new FakeAiClient([new AiResponse($text, StopReason::Complete, $tokens, 'fake', $model)], $model);
    }
}
