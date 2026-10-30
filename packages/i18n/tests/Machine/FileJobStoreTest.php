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
}
