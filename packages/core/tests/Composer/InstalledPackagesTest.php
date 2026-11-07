<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Composer;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Composer\InstalledPackages;

final class InstalledPackagesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/installed_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,*/}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && unlink($f);
        }
        @rmdir($this->dir . '/cache');
        @rmdir($this->dir);
    }

    private function installed(array $packages, int $mtime): string
    {
        $path = $this->dir . '/installed.json';
        file_put_contents($path, json_encode(['packages' => $packages]));
        touch($path, $mtime);

        return $path;
    }

    private function reload(): void
    {
        // A fresh process: the in-process memo must not be what proves reuse.
        (new \ReflectionProperty(InstalledPackages::class, 'memo'))->setValue(null, []);
    }

    public function test_installed_json_is_decoded_once_and_then_served_from_cache(): void
    {
        $path = $this->installed([['name' => 'a/b', 'install-path' => '../a/b', 'extra' => ['folio' => ['extension' => 'X']]]], 1_000_000);
        $cache = $this->dir . '/cache';

        $first = InstalledPackages::read($path, $cache);
        $this->assertSame('X', $first[0]['extension']);
        $this->assertCount(1, glob($cache . '/installed-*.php'));

        // Same mtime and size, different content: a decode would show it, the cache does not.
        $size = filesize($path);
        file_put_contents($path, str_replace('"X"', '"Y"', (string) file_get_contents($path)));
        touch($path, 1_000_000);
        $this->assertSame($size, filesize($path));
        $this->reload();

        $this->assertSame('X', InstalledPackages::read($path, $cache)[0]['extension']);
    }

    public function test_a_changed_installed_json_is_read_again(): void
    {
        $path = $this->installed([['name' => 'a/b']], 1_000_000);
        $cache = $this->dir . '/cache';
        $this->assertSame(['a/b'], array_column(InstalledPackages::read($path, $cache), 'name'));
        $this->reload();

        $this->installed([['name' => 'a/b'], ['name' => 'c/d', 'extra' => ['preflow' => ['translations' => ['g' => 'lang']]]]], 1_000_100);

        $packages = InstalledPackages::read($path, $cache);
        $this->assertSame(['a/b', 'c/d'], array_column($packages, 'name'));
        $this->assertSame(['g' => 'lang'], $packages[1]['translations']);
    }

    public function test_a_corrupt_cache_file_falls_back_to_parsing(): void
    {
        $path = $this->installed([['name' => 'a/b']], 1_000_000);
        $cache = $this->dir . '/cache';
        InstalledPackages::read($path, $cache);
        $file = glob($cache . '/installed-*.php')[0];
        file_put_contents($file, '<?php return [ broken');
        $this->reload();

        $this->assertSame(['a/b'], array_column(InstalledPackages::read($path, $cache), 'name'));
    }

    public function test_a_missing_file_is_empty(): void
    {
        $this->assertSame([], InstalledPackages::read($this->dir . '/nope.json', $this->dir . '/cache'));
    }
}
