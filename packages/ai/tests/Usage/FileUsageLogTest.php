<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests\Usage;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\TokenUsage;
use Preflow\Ai\Usage\FileUsageLog;
use Preflow\Ai\Usage\UsageEntry;

final class FileUsageLogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ai_usage_' . bin2hex(random_bytes(4)) . '/nested';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        @rmdir(dirname($this->dir));
    }

    private function entry(string $at, string $purpose = 'translate', ?float $cost = 0.5, bool $failed = false): UsageEntry
    {
        return new UsageEntry(new \DateTimeImmutable($at), $purpose, 'claude', 'claude-sonnet-5-5', new TokenUsage(10, 20, 5), $cost, $failed);
    }

    public function test_appends_jsonl_per_month_and_reads_range(): void
    {
        $log = new FileUsageLog($this->dir);
        $log->record($this->entry('2026-09-30 23:00:00', 'a'));
        $log->record($this->entry('2026-10-02 10:00:00', 'b'));
        $log->record($this->entry('2026-10-15 10:00:00', 'c', null, true));

        $this->assertFileExists($this->dir . '/usage-2026-09.jsonl');
        $this->assertCount(2, file($this->dir . '/usage-2026-10.jsonl'));

        $october = $log->between(new \DateTimeImmutable('2026-10-01 00:00:00'), new \DateTimeImmutable('2026-10-31 23:59:59'));
        $this->assertSame(['b', 'c'], array_map(static fn (UsageEntry $e) => $e->purpose, $october));
        $this->assertNull($october[1]->costUsd);
        $this->assertTrue($october[1]->failed);
        $this->assertSame(5, $october[0]->usage->cachedInput);
        $this->assertSame(0.5, $october[0]->costUsd);

        $both = $log->between(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-10-31 23:59:59'));
        $this->assertCount(3, $both);
        $this->assertSame([], $log->between(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30')));
    }

    public function test_skips_damaged_lines(): void
    {
        $log = new FileUsageLog($this->dir);
        $log->record($this->entry('2026-10-02 10:00:00'));
        file_put_contents($this->dir . '/usage-2026-10.jsonl', "not json\n", FILE_APPEND);

        $this->assertCount(1, $log->between(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')));
    }

    public function test_no_prompt_or_text_in_file(): void
    {
        $log = new FileUsageLog($this->dir);
        $log->record($this->entry('2026-10-02 10:00:00'));

        $line = json_decode((string) file_get_contents($this->dir . '/usage-2026-10.jsonl'), true);
        $this->assertSame(['at', 'purpose', 'provider', 'model', 'input', 'output', 'cached', 'cost', 'failed'], array_keys($line));
    }
}
