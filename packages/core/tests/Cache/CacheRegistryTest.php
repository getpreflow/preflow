<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Cache\CacheCleared;
use Preflow\Core\Cache\CacheRegistry;
use Preflow\Core\Cache\CacheSource;
use Preflow\Core\Cache\CacheStats;

final class CacheRegistryTest extends TestCase
{
    public function test_registered_sources_are_found_by_key(): void
    {
        $registry = new CacheRegistry();
        $a = $this->source('a');
        $registry->register($a);

        $this->assertSame($a, $registry->get('a'));
        $this->assertNull($registry->get('missing'));
    }

    public function test_all_keeps_the_registration_order(): void
    {
        $registry = new CacheRegistry();
        $b = $this->source('b');
        $a = $this->source('a');
        $registry->register($b);
        $registry->register($a);

        $this->assertSame([$b, $a], $registry->all());
    }

    public function test_a_duplicate_key_throws(): void
    {
        $registry = new CacheRegistry();
        $registry->register($this->source('a'));

        $this->expectException(\LogicException::class);
        $registry->register($this->source('a'));
    }

    public function test_clear_all_returns_the_result_of_each_source_in_order(): void
    {
        $registry = new CacheRegistry();
        $registry->register($this->source('a'));
        $registry->register($this->source('b'));

        $cleared = $registry->clearAll();

        $this->assertSame(['a', 'b'], array_keys($cleared));
        $this->assertContainsOnlyInstancesOf(CacheCleared::class, $cleared);
    }

    private function source(string $key): CacheSource
    {
        return new class ($key) implements CacheSource {
            public function __construct(private string $key) {}

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return $this->key;
            }

            public function stats(): CacheStats
            {
                return new CacheStats(0, 0);
            }

            public function clear(): CacheCleared
            {
                return new CacheCleared(0, 0);
            }
        };
    }
}
