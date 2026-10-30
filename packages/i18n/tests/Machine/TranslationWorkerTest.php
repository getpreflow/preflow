<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiUnavailable;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\I18n\Machine\AiTranslator;
use Preflow\I18n\Machine\Estimator;
use Preflow\I18n\Machine\FileJobStore;
use Preflow\I18n\Machine\FileRecordLease;
use Preflow\I18n\Machine\JobRunner;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSources;
use Preflow\I18n\Machine\TranslationWorker;
use Preflow\I18n\Tests\Machine\Support\ArrayTextSource;

final class TranslationWorkerTest extends TestCase
{
    use MachineTestKit;

    private string $dir;
    private FileJobStore $store;
    private ArrayTextSource $source;
    private int $clock = 1_800_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/worker_' . bin2hex(random_bytes(4));
        $this->store = new FileJobStore($this->dir . '/jobs');
        $this->source = new ArrayTextSource([
            '1' => ['title' => 'One'],
            '2' => ['title' => 'Two'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/jobs/*') ?: [], glob($this->dir . '/jobs/.*.lock') ?: [], glob($this->dir . '/leases/*') ?: [], glob($this->dir . '/*.json') ?: []) as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/jobs');
        @rmdir($this->dir . '/leases');
        @rmdir($this->dir);
    }

    /** @param list<mixed> $script every model request advances the clock by 30 seconds */
    private function worker(array $script, bool $enabled = true, ?JobRunner &$runner = null): TranslationWorker
    {
        $advance = function (callable|\Throwable $entry): callable {
            return function ($request) use ($entry) {
                $this->clock += 30;

                return $entry instanceof \Throwable ? $entry : $entry($request);
            };
        };
        $fake = new FakeAiClient(array_map($advance, $script));
        $ai = $this->ai($fake, [], $enabled);
        $sources = new TextSources();
        $sources->add('games', fn () => $this->source);
        $runner = new JobRunner($sources, new AiTranslator($ai, $this->settings()), new Estimator($ai), $this->store, new FileRecordLease($this->dir . '/leases'));

        return new TranslationWorker($runner, $this->store, $ai, $this->dir . '/worker.json', fn () => new \DateTimeImmutable('@' . $this->clock));
    }

    private function queued(JobRunner $runner, string $at): string
    {
        $job = $runner->plan('games', ['de'], Scope::Needed);
        $runner->start($job->id);
        $this->store->update($job->id, static function ($j) use ($at): void {
            $j->queuedAt = $at;
        });

        return $job->id;
    }

    public function test_works_queued_jobs_oldest_first_within_budget(): void
    {
        $worker = $this->worker([self::echo()], runner: $runner);
        $older = $this->queued($runner, '2026-10-30T10:00:00+00:00');
        $younger = $this->queued($runner, '2026-10-30T11:00:00+00:00');

        $lines = [];
        $report = $worker->work(50, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertSame(2, $report->records);
        $this->assertNull($report->stoppedBecause);
        $this->assertSame('done', $this->store->get($older)->state);
        $this->assertSame('queued', $this->store->get($younger)->state);
        $this->assertSame(['games 1 de: done', 'games 2 de: done'], $lines);
    }

    public function test_ai_off_leaves_jobs_queued(): void
    {
        $worker = $this->worker([self::echo()], enabled: false, runner: $runner);
        $id = $this->queued($runner, '2026-10-30T10:00:00+00:00');

        $report = $worker->work();

        $this->assertSame(0, $report->records);
        $this->assertSame('queued', $this->store->get($id)->state);
        $this->assertNotNull($worker->lastRun());
    }

    public function test_unavailable_stops_that_job_and_ends_the_run(): void
    {
        $worker = $this->worker([new AiUnavailable('no key')], runner: $runner);
        $first = $this->queued($runner, '2026-10-30T10:00:00+00:00');
        $second = $this->queued($runner, '2026-10-30T11:00:00+00:00');

        $report = $worker->work();

        $this->assertSame('no key', $report->stoppedBecause);
        $this->assertSame('stopped', $this->store->get($first)->state);
        $this->assertSame('no key', $this->store->get($first)->message);
        $this->assertSame('queued', $this->store->get($second)->state);
    }

    public function test_continues_a_047_running_job(): void
    {
        $worker = $this->worker([self::echo()], runner: $runner);
        $id = $this->queued($runner, '2026-10-30T10:00:00+00:00');
        $this->store->update($id, static function ($j): void {
            $j->state = 'running';
            $j->queuedAt = null;
        });

        $report = $worker->work(1000);

        $this->assertSame(2, $report->records);
        $this->assertSame('done', $this->store->get($id)->state);
    }

    public function test_last_run_reads_the_heartbeat(): void
    {
        $worker = $this->worker([self::echo()]);
        $this->assertNull($worker->lastRun());

        $worker->work();

        $this->assertSame($this->clock, $worker->lastRun()->getTimestamp());
    }
}
