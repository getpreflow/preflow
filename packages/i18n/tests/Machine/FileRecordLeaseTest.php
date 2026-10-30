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
}
