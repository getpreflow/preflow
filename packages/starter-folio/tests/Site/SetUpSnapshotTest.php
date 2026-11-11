<?php

declare(strict_types=1);

namespace Tests\Site;

use PHPUnit\Framework\TestCase;
use Tests\Support\StarterApp;

/**
 * Migrate and the demo seed run once per test run: a later test's copy gets their result from
 * the set-up snapshot instead of running them again (what keeps `composer test` fast).
 */
final class SetUpSnapshotTest extends TestCase
{
    use StarterApp;

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    public function test_a_second_copy_reuses_the_migrated_and_seeded_set_up(): void
    {
        if (getenv('PREFLOW_TEST_SNAPSHOTS') === '0') {
            $this->markTestSkipped('Set-up snapshots are switched off (PREFLOW_TEST_SNAPSHOTS=0).');
        }
        $this->bootStarter();
        $this->get('/')->assertStatus(200);
        $this->removeStarter();

        $this->bootStarter();

        $this->assertTrue($this->setUpRestored, 'the second copy ran migrate and the seed again');
        $this->get('/')->assertStatus(200);
    }
}
