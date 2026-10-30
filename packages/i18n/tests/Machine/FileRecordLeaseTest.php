<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Machine\FileRecordLease;

final class FileRecordLeaseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lease_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_claim_is_exclusive_until_released(): void
    {
        $lease = new FileRecordLease($this->dir);

        $this->assertTrue($lease->claim('game', 'g1'));
        $this->assertFalse($lease->claim('game', 'g1'));
        $this->assertTrue($lease->claim('game', 'g2'));

        $lease->release('game', 'g1');
        $this->assertTrue($lease->claim('game', 'g1'));
    }

    public function test_an_expired_claim_is_taken_over(): void
    {
        $t = 1_700_000_000;
        $lease = new FileRecordLease($this->dir, static function () use (&$t): int { return $t; });

        $this->assertTrue($lease->claim('game', 'g1'));
        $t += 599;
        $this->assertFalse($lease->claim('game', 'g1'));
        $t += 2;
        $this->assertTrue($lease->claim('game', 'g1'));
    }

    public function test_an_empty_lease_file_counts_as_held(): void
    {
        $lease = new FileRecordLease($this->dir);
        $this->assertTrue($lease->claim('game', 'g1'));
        file_put_contents($this->dir . '/' . sha1("game\0g1"), '');

        $this->assertFalse($lease->claim('game', 'g1'));
    }

    public function test_a_takeover_is_exclusive(): void
    {
        $t = 1_700_000_000;
        $now = static function () use (&$t): int { return $t; };
        $first = new FileRecordLease($this->dir, $now);
        $second = new FileRecordLease($this->dir, $now);

        $this->assertTrue($first->claim('game', 'g1'));
        $t += 601;
        $this->assertTrue($first->claim('game', 'g1'));
        $this->assertFalse($second->claim('game', 'g1'));
    }

    public function test_release_by_a_non_holder_leaves_the_claim(): void
    {
        $t = 1_700_000_000;
        $now = static function () use (&$t): int { return $t; };
        $first = new FileRecordLease($this->dir, $now);
        $second = new FileRecordLease($this->dir, $now);

        $this->assertTrue($first->claim('game', 'g1'));
        $second->release('game', 'g1');
        $this->assertFalse($second->claim('game', 'g1'), 'a lease that never held the record cannot free it');

        // The first worker overran its claim and the second took over: the first one's release must not free it.
        $t += 601;
        $this->assertTrue($second->claim('game', 'g1'));
        $first->release('game', 'g1');
        $this->assertFalse($first->claim('game', 'g1'));

        $second->release('game', 'g1');
        $this->assertTrue($first->claim('game', 'g1'));
    }

    public function test_renew_extends_the_expiry(): void
    {
        $t = 1_700_000_000;
        $now = static function () use (&$t): int { return $t; };
        $holder = new FileRecordLease($this->dir, $now);
        $other = new FileRecordLease($this->dir, $now);

        $this->assertTrue($holder->claim('game', 'g1'));
        $t += 500;
        $this->assertTrue($holder->renew('game', 'g1'));
        $t += 500;
        $this->assertFalse($other->claim('game', 'g1'), 'renewed for another 600 s from t+500');
        $t += 101;
        $this->assertTrue($other->claim('game', 'g1'));
    }

    public function test_renew_fails_for_a_non_holder(): void
    {
        $t = 1_700_000_000;
        $now = static function () use (&$t): int { return $t; };
        $first = new FileRecordLease($this->dir, $now);
        $second = new FileRecordLease($this->dir, $now);

        $this->assertFalse($first->renew('game', 'g1'), 'nothing claimed');
        $this->assertTrue($first->claim('game', 'g1'));
        $this->assertFalse($second->renew('game', 'g1'));
        $t += 601;
        $this->assertTrue($second->claim('game', 'g1'));
        $this->assertFalse($first->renew('game', 'g1'), 'the claim was taken over');
    }

    public function test_an_empty_lease_file_older_than_the_ttl_is_taken_over(): void
    {
        // A worker that died between creating the file and writing its expiry must not block the record forever.
        $lease = new FileRecordLease($this->dir);
        $this->assertTrue($lease->claim('game', 'g1'));
        $path = $this->dir . '/' . sha1("game\0g1");
        file_put_contents($path, '');
        touch($path, time() - 601);

        $this->assertTrue((new FileRecordLease($this->dir))->claim('game', 'g1'));
    }

    public function test_an_unparseable_lease_file_older_than_the_ttl_is_taken_over(): void
    {
        $lease = new FileRecordLease($this->dir);
        $this->assertTrue($lease->claim('game', 'g1'));
        $path = $this->dir . '/' . sha1("game\0g1");
        file_put_contents($path, 'garbage');
        $this->assertFalse((new FileRecordLease($this->dir))->claim('game', 'g1'), 'fresh: still held');

        touch($path, time() - 601);
        $this->assertTrue((new FileRecordLease($this->dir))->claim('game', 'g1'));
    }
}
