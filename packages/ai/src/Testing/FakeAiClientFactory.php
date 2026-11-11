<?php

declare(strict_types=1);

namespace Preflow\Ai\Testing;

use Preflow\Ai\AiClient;
use Preflow\Ai\AiClientFactory;
use Preflow\Ai\AiResponse;
use Preflow\Ai\ExplainsUnavailability;
use Preflow\Ai\StopReason;
use Preflow\Ai\TokenUsage;
use Preflow\Core\Container\Attributes\Config;

/**
 * config/ai.php `'provider' => 'fake'`: AI without a key and without cost, for your machine and
 * for tests. Every request gets the same answer: `options.fake.answer` (an array is sent as JSON,
 * a string as text; default {"ok":true}, which ai:check accepts), with `options.fake.usage`
 * ([input, output] tokens, default [10, 10]). The model is `models.fake`, default `fake-model`,
 * which the price table doesn't know, so its cost shows as unknown.
 *
 * Only while developing: with app.debug off it reports "not available", so AI stays off and a
 * live site never writes its canned answers into content, unless `options.fake.allow_live` is
 * true (tests that run with debug off). The refusal is logged once and shown by ai:check and
 * Settings → AI. For a script (answers in order, a function per request), write a factory of
 * your own that returns a FakeAiClient.
 *
 * @since 0.66
 */
final class FakeAiClientFactory implements AiClientFactory, ExplainsUnavailability
{
    private const string REASON = 'The fake AI provider answers only while developing (app.debug on), or with options.fake.allow_live set to true.';

    private static bool $warned = false;

    public function __construct(
        #[Config('app.debug', 0)] private readonly int|bool|string|null $debug = 0,
    ) {}

    public function available(#[\SensitiveParameter] array $options): bool
    {
        if ($this->allowed($options)) {
            return true;
        }
        if (!self::$warned) {
            self::$warned = true;
            error_log('Preflow AI: ' . self::REASON . ' AI is off.');
        }

        return false;
    }

    public function unavailableReason(#[\SensitiveParameter] array $options): ?string
    {
        return $this->allowed($options) ? null : self::REASON;
    }

    public function create(#[\SensitiveParameter] array $options): AiClient
    {
        $model = is_string($options['model'] ?? null) && $options['model'] !== '' ? $options['model'] : 'fake-model';
        $answer = $options['answer'] ?? ['ok' => true];
        $usage = is_array($options['usage'] ?? null) ? array_values($options['usage']) : [10, 10];
        $tokens = new TokenUsage((int) ($usage[0] ?? 0), (int) ($usage[1] ?? 0));
        $text = is_string($answer) ? $answer : json_encode($answer, JSON_THROW_ON_ERROR);

        return new FakeAiClient([new AiResponse($text, StopReason::Complete, $tokens, 'fake', $model)], $model, record: false);
    }

    /** @param array<string, mixed> $options */
    private function allowed(array $options): bool
    {
        return (bool) filter_var($this->debug, FILTER_VALIDATE_BOOL) || (int) $this->debug > 0 || ($options['allow_live'] ?? false) === true;
    }
}
