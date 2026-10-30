<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\Ai;
use Preflow\Ai\AiCheck;
use Preflow\Ai\AiCheckResult;
use Preflow\Ai\AiRequestFailed;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Ai\Usage\Pricing;
use Preflow\Ai\Usage\UsageEntry;
use Preflow\Ai\Usage\UsageLog;

final class AiCheckTest extends TestCase
{
    /** @param list<mixed> $script */
    private function check(array $script, bool $enabled = true): AiCheckResult
    {
        $log = new class implements UsageLog {
            public function record(UsageEntry $e): void {}
            public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array { return []; }
        };
        $ai = new Ai(
            ['enabled' => $enabled, 'provider' => 'claude', 'models' => ['claude' => 'claude-sonnet-5-5'], 'keys' => ['claude' => 'K']],
            $log,
            Pricing::withOverrides([]),
            static fn (): string => 'secret',
            static fn () => new FakeAiClient($script, 'claude-sonnet-5-5'),
        );

        return (new AiCheck($ai))->run();
    }

    public function test_ok(): void
    {
        $r = $this->check([FakeAiClient::json(['ok' => true])]);

        $this->assertTrue($r->ok());
        $this->assertSame(10, $r->usage?->input);
    }

    public function test_off_sends_nothing(): void
    {
        $this->assertSame(AiCheckResult::OFF, $this->check([], false)->state);
    }

    public function test_failure_carries_the_message(): void
    {
        $r = $this->check([new AiRequestFailed('boom')]);

        $this->assertSame(AiCheckResult::FAILED, $r->state);
        $this->assertSame('boom', $r->message);
    }

    public function test_unexpected_answer(): void
    {
        $this->assertSame(AiCheckResult::UNEXPECTED, $this->check([FakeAiClient::json(['ok' => false])])->state);
    }
}
