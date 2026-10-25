<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Cache\OpcacheStatus;

final class OpcacheStatusTest extends TestCase
{
    public function test_enabled_follows_opcache_get_status(): void
    {
        $expected = function_exists('opcache_get_status') && is_array(@opcache_get_status(false));

        $this->assertSame($expected, (new OpcacheStatus())->enabled());
    }

    public function test_stats_are_null_when_disabled_and_shaped_when_enabled(): void
    {
        $status = new OpcacheStatus();
        $stats = $status->stats();

        if (!$status->enabled()) {
            $this->assertNull($stats);
            return;
        }

        $this->assertSame(['memory_used', 'memory_free', 'scripts', 'hit_rate'], array_keys($stats));
        $this->assertIsFloat($stats['hit_rate']);
    }
}
