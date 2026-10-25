<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Cache\FileCache;

final class FileCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/filecache_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_a_present_file_is_counted_and_deleted_on_clear(): void
    {
        file_put_contents($this->dir . '/routes.php', '1234');
        $cache = new FileCache('routes', 'Route table', $this->dir . '/routes.php', 'note');

        $this->assertSame(1, $cache->stats()->entries);
        $this->assertSame(4, $cache->stats()->bytes);
        $this->assertSame('note', $cache->stats()->note);

        $cleared = $cache->clear();

        $this->assertSame(1, $cleared->entries);
        $this->assertSame(4, $cleared->bytes);
        $this->assertFileDoesNotExist($this->dir . '/routes.php');
    }

    public function test_a_missing_file_is_empty(): void
    {
        $cache = new FileCache('routes', 'Route table', $this->dir . '/none.php');

        $this->assertSame(0, $cache->stats()->entries);
        $this->assertSame(0, $cache->stats()->bytes);
        $this->assertSame(0, $cache->clear()->entries);
    }
}
