<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests\Providers;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiRequest;
use Preflow\Ai\Providers\ClaudeClient;
use Preflow\Ai\StopReason;
use Preflow\Ai\Tuning;

/**
 * Opt-in smoke tests against the real providers. Each one is skipped unless its key env
 * var is set, so the normal suite never calls a provider.
 */
final class LiveProvidersTest extends TestCase
{
    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function test_claude_answers_json(): void
    {
        $key = self::env('ANTHROPIC_API_KEY');
        if ($key === null) {
            $this->markTestSkipped('ANTHROPIC_API_KEY is not set.');
        }

        $client = ClaudeClient::fromKey($key, self::env('ANTHROPIC_MODEL') ?? 'claude-sonnet-5-5');
        $response = $client->complete(new AiRequest(
            'smoke',
            'Answer with {"ok":true}.',
            new Tuning(['You answer with JSON only.']),
            ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'], 'additionalProperties' => false],
            maxOutputTokens: 200,
        ));

        $this->assertSame(StopReason::Complete, $response->stop);
        $this->assertSame(['ok' => true], $response->json());
        $this->assertGreaterThan(0, $response->usage->input);
    }
}
