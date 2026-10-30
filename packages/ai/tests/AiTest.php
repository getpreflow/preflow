<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Ai\Ai;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiUnavailable;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Ai\TokenUsage;
use Preflow\Ai\Usage\Pricing;
use Preflow\Ai\Usage\UsageEntry;
use Preflow\Ai\Usage\UsageLog;

final class AiTest extends TestCase
{
    private const KEY = 'sk-secret-key-123';

    /** @return array<string, mixed> */
    private function config(bool $enabled = true): array
    {
        return [
            'enabled' => $enabled,
            'provider' => 'claude',
            'models' => ['claude' => 'claude-sonnet-5-5', 'gemini' => 'gemini-2.5-flash'],
            'keys' => ['claude' => 'ANTHROPIC_API_KEY', 'gemini' => 'GEMINI_API_KEY'],
        ];
    }

    private function log(): UsageLog
    {
        return new class implements UsageLog {
            /** @var list<UsageEntry> */
            public array $entries = [];

            public function record(UsageEntry $e): void
            {
                $this->entries[] = $e;
            }

            public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return $this->entries;
            }
        };
    }

    private function ai(bool $enabled, ?string $key, ?\Closure $factory = null, ?UsageLog $log = null): Ai
    {
        return new Ai(
            $this->config($enabled),
            $log ?? $this->log(),
            Pricing::withOverrides([]),
            static fn (string $name): ?string => $name === 'ANTHROPIC_API_KEY' ? $key : null,
            $factory,
        );
    }

    /** @return iterable<string, array{bool, ?string, bool}> */
    public static function combinations(): iterable
    {
        yield 'flag and key' => [true, self::KEY, true];
        yield 'flag only' => [true, null, false];
        yield 'key only' => [false, self::KEY, false];
        yield 'neither' => [false, null, false];
        yield 'empty key counts as absent' => [true, '', false];
    }

    #[DataProvider('combinations')]
    public function test_disabled_without_flag_or_key(bool $flag, ?string $key, bool $expected): void
    {
        $this->assertSame($expected, $this->ai($flag, $key)->enabled());
    }

    public function test_default_env_reads_getenv_then_env_superglobal(): void
    {
        $config = $this->config();
        $config['keys']['claude'] = 'PREFLOW_AI_TEST_KEY';
        $ai = new Ai($config, $this->log(), Pricing::withOverrides([]));
        $this->assertFalse($ai->enabled());

        $_ENV['PREFLOW_AI_TEST_KEY'] = 'from-env-array';
        try {
            $this->assertTrue($ai->enabled());
        } finally {
            unset($_ENV['PREFLOW_AI_TEST_KEY']);
        }
    }

    public function test_status_never_contains_key(): void
    {
        $status = $this->ai(true, self::KEY)->status();

        $this->assertTrue($status->enabled);
        $this->assertTrue($status->keyPresent);
        $this->assertSame('claude', $status->provider);
        $this->assertSame('claude-sonnet-5-5', $status->model);
        $this->assertStringNotContainsString(self::KEY, serialize($status));
        $this->assertStringNotContainsString(self::KEY, (string) json_encode($status));
    }

    public function test_client_throws_unavailable_when_disabled(): void
    {
        $this->expectException(AiUnavailable::class);
        $this->ai(false, self::KEY)->client();
    }

    public function test_client_is_logged_and_records_usage_with_cost(): void
    {
        $log = $this->log();
        $seen = [];
        $fake = new FakeAiClient([FakeAiClient::json(['ok' => true], new TokenUsage(1_000_000, 1_000_000))], 'claude-sonnet-5-5');
        $ai = $this->ai(true, self::KEY, static function (string $provider, string $model, string $key) use ($fake, &$seen) {
            $seen = [$provider, $model, $key];

            return $fake;
        }, $log);

        $ai->client()->complete(new AiRequest('translate', 'secret prompt text'));

        $this->assertSame(['claude', 'claude-sonnet-5-5', self::KEY], $seen);
        $this->assertCount(1, $log->entries);
        $this->assertSame('translate', $log->entries[0]->purpose);
        $this->assertSame(1_000_000, $log->entries[0]->usage->input);
        $this->assertSame(12.0, $log->entries[0]->costUsd);
        $this->assertFalse($log->entries[0]->failed);
    }

    public function test_failed_request_is_logged_with_zero_tokens_and_rethrown(): void
    {
        $log = $this->log();
        $fake = new FakeAiClient([new \Preflow\Ai\AiRequestFailed('boom')], 'claude-sonnet-5-5');
        $ai = $this->ai(true, self::KEY, static fn () => $fake, $log);

        try {
            $ai->client()->complete(new AiRequest('translate', 'x'));
            $this->fail('expected AiRequestFailed');
        } catch (\Preflow\Ai\AiRequestFailed) {
        }

        $this->assertCount(1, $log->entries);
        $this->assertTrue($log->entries[0]->failed);
        $this->assertSame(0, $log->entries[0]->usage->input);
    }
}
