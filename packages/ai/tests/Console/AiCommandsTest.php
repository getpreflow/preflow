<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests\Console;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\Ai;
use Preflow\Ai\Console\AiCheckCommand;
use Preflow\Ai\Console\AiUsageCommand;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Ai\TokenUsage;
use Preflow\Ai\Usage\FileUsageLog;
use Preflow\Ai\Usage\Pricing;
use Preflow\Ai\Usage\UsageEntry;
use Preflow\Core\Application;

final class AiCommandsTest extends TestCase
{
    private string $dir;

    /** @var resource */
    private $err;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ai_cmd_' . bin2hex(random_bytes(4));
        $this->err = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    /** @param array<string, mixed> $config */
    private function app(Ai $ai, ?FileUsageLog $log = null): \Closure
    {
        $app = Application::create(['app' => ['debug' => true]]);
        $app->container()->instance(Ai::class, $ai);
        $app->container()->instance(\Preflow\Ai\Usage\UsageLog::class, $log ?? new FileUsageLog($this->dir));

        return static fn (): Application => $app;
    }

    private function ai(bool $enabled, ?FakeAiClient $fake = null): Ai
    {
        return new Ai(
            ['enabled' => $enabled, 'provider' => 'claude', 'models' => ['claude' => 'claude-sonnet-5-5'], 'keys' => ['claude' => 'K']],
            new FileUsageLog($this->dir),
            Pricing::withOverrides([]),
            static fn (string $n): ?string => 'secret-value',
            static fn () => $fake ?? new FakeAiClient([FakeAiClient::json(['ok' => true])], 'claude-sonnet-5-5'),
        );
    }

    public function test_check_succeeds_prints_no_key_and_logs_the_call(): void
    {
        ob_start();
        $code = (new AiCheckCommand($this->app($this->ai(true)), $this->err))->execute([]);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('OK', $out);
        $this->assertStringNotContainsString('secret-value', $out);
        $this->assertCount(1, glob($this->dir . '/usage-*.jsonl') ?: []);
    }

    public function test_check_fails_when_off(): void
    {
        ob_start();
        $code = (new AiCheckCommand($this->app($this->ai(false)), $this->err))->execute([]);
        ob_end_clean();

        $this->assertSame(1, $code);
    }

    public function test_check_fails_on_wrong_answer(): void
    {
        $fake = new FakeAiClient([FakeAiClient::json(['ok' => false])], 'claude-sonnet-5-5');
        ob_start();
        $code = (new AiCheckCommand($this->app($this->ai(true, $fake)), $this->err))->execute([]);
        ob_end_clean();

        $this->assertSame(1, $code);
    }

    public function test_usage_groups_by_purpose_and_model(): void
    {
        $log = new FileUsageLog($this->dir);
        $at = new \DateTimeImmutable('2026-10-05 10:00:00');
        $log->record(new UsageEntry($at, 'translate', 'claude', 'claude-sonnet-5-5', new TokenUsage(1000, 500), 0.007));
        $log->record(new UsageEntry($at, 'translate', 'claude', 'claude-sonnet-5-5', new TokenUsage(1000, 500), 0.007));
        $log->record(new UsageEntry($at, 'vibe', 'gemini', 'odd-model', new TokenUsage(10, 10), null));

        ob_start();
        $code = (new AiUsageCommand($this->app($this->ai(true), $log), $this->err))->execute(['--month=2026-10']);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/translate\s+claude-sonnet-5-5\s+2\s+0\s+2000\s+1000\s+0\.0140/', $out);
        $this->assertMatchesRegularExpression('/vibe\s+odd-model\s+1\s+0\s+10\s+10\s+0\.0000\+/', $out);
    }

    public function test_usage_rejects_a_bad_month(): void
    {
        $this->assertSame(1, (new AiUsageCommand($this->app($this->ai(true)), $this->err))->execute(['--month=october']));
    }
}
