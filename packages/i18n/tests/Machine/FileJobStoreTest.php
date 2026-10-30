<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Machine\Estimate;
use Preflow\I18n\Machine\FileJobStore;
use Preflow\I18n\Machine\Job;
use Preflow\I18n\Machine\Scope;

final class FileJobStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jobstore_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_roundtrip(): void
    {
        $job = new Job('abc123def4567890', 'games', ['de', 'fr'], Scope::All, 'be formal', new Estimate(3, 120, 400, 200, 0.0123, 1), 'running', 0.5, 'msg');
        $job->items = ['1' => ['status' => 'done', 'translated' => 2, 'failed' => 0, 'warnings' => 1, 'message' => null, 'draft_changes' => true]];
        $store = new FileJobStore($this->dir);

        $store->save($job);

        $this->assertEquals($job, $store->get('abc123def4567890'));
        $this->assertSame([], glob($this->dir . '/*.tmp'), 'no temp files are left behind');
        $this->assertNull($store->get('missing'));
    }

    public function test_null_estimate_cost_survives(): void
    {
        $job = new Job('j1', 'games', ['de'], Scope::Needed, null, new Estimate(0, 0, 0, 0, null, 0));
        $store = new FileJobStore($this->dir);
        $store->save($job);

        $this->assertNull($store->get('j1')->estimate->costUsd);
    }

    public function test_unsafe_ids_are_rejected(): void
    {
        $store = new FileJobStore($this->dir);
        $job = new Job('../evil', 'games', ['de'], Scope::Needed, null, new Estimate(0, 0, 0, 0, null, 0));

        $this->assertNull($store->get('../evil'));
        $this->expectException(\InvalidArgumentException::class);
        $store->save($job);
    }

    public function test_recent_lists_the_last_saved_jobs_first(): void
    {
        $store = new FileJobStore($this->dir);
        foreach (['aaaa', 'bbbb', 'cccc'] as $i => $id) {
            $store->save(new Job($id, 'games', ['de'], Scope::Needed, null, new Estimate(0, 0, 0, 0, null, 0)));
            touch($this->dir . '/' . $id . '.json', 1_700_000_000 + [10, 30, 20][$i]);
        }

        $this->assertSame(['bbbb', 'cccc', 'aaaa'], array_map(static fn (Job $j): string => $j->id, $store->recent(5)));
        $this->assertSame(['bbbb', 'cccc'], array_map(static fn (Job $j): string => $j->id, $store->recent(2)));
        $this->assertSame([], (new FileJobStore($this->dir . '/none'))->recent(5));
    }

    public function test_new_fields_round_trip(): void
    {
        $job = new Job('j2', 'games', ['de'], Scope::Needed, null, new Estimate(0, 0, 0, 0, null, 0), 'queued', prefix: 'flow', confirmedLive: true, queuedBy: 'anna', queuedAt: '2026-10-30T12:00:00+00:00');
        $store = new FileJobStore($this->dir);
        $store->save($job);

        $loaded = $store->get('j2');
        $this->assertSame('queued', $loaded->state);
        $this->assertSame('flow', $loaded->prefix);
        $this->assertTrue($loaded->confirmedLive);
        $this->assertSame('anna', $loaded->queuedBy);
        $this->assertSame('2026-10-30T12:00:00+00:00', $loaded->queuedAt);
    }

    public function test_a_047_running_job_loads_as_confirmed(): void
    {
        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/old1.json', json_encode([
            'id' => 'old1', 'collection' => 'games', 'locales' => ['de'], 'scope' => 'needed', 'note' => null,
            'state' => 'running', 'message' => null, 'items' => [], 'estimate' => [], 'cost_usd' => 0.0, 'cost_unknown' => false,
        ]));

        $job = (new FileJobStore($this->dir))->get('old1');
        $this->assertSame('running', $job->state);
        $this->assertNull($job->prefix);
        $this->assertTrue($job->confirmedLive);
    }
}
