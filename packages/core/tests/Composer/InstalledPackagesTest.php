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
        $this->assertSame('X', InstalledPackages::extra($first[0], 'folio.extension'));
        $this->assertCount(1, glob($cache . '/installed-*.php'));

        // Same mtime and size, different content: a decode would show it, the cache does not.
        $size = filesize($path);
        file_put_contents($path, str_replace('"X"', '"Y"', (string) file_get_contents($path)));
        touch($path, 1_000_000);
        $this->assertSame($size, filesize($path));
        $this->reload();

        $this->assertSame('X', InstalledPackages::extra(InstalledPackages::read($path, $cache)[0], 'folio.extension'));
    }

    /**
     * Like the other caches, the composer cache moves with the project (a release folder, a test's
     * copy of the project): it names installed.json relative to the cache folder, never by the
     * project's absolute path.
     */
    public function test_the_cache_moves_with_the_project(): void
    {
        $a = $this->dir . '/a';
        mkdir($a . '/vendor/composer', 0777, true);
        file_put_contents($a . '/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'a/b', 'extra' => ['folio' => ['extension' => 'X']]]]]));
        touch($a . '/vendor/composer/installed.json', 1_000_000);

        InstalledPackages::read($a . '/vendor/composer/installed.json', $a . '/storage/cache/composer');
        $files = glob($a . '/storage/cache/composer/installed-*.php') ?: [];
        $this->assertCount(1, $files);
        $this->assertStringNotContainsString($a, (string) file_get_contents($files[0]), 'no absolute path in the cache');

        $b = $this->dir . '/b';
        exec('cp -Rp ' . escapeshellarg($a) . ' ' . escapeshellarg($b));
        // Same mtime and size, different content: only a cache hit still answers X.
        file_put_contents($b . '/vendor/composer/installed.json', str_replace('"X"', '"Y"', (string) file_get_contents($b . '/vendor/composer/installed.json')));
        touch($b . '/vendor/composer/installed.json', 1_000_000);
        $this->reload();

        $this->assertSame('X', InstalledPackages::extra(InstalledPackages::read($b . '/vendor/composer/installed.json', $b . '/storage/cache/composer')[0], 'folio.extension'));
        exec('rm -rf ' . escapeshellarg($a) . ' ' . escapeshellarg($b));
    }

    public function test_keeps_extra_as_is(): void
    {
        $extra = [
            'folio' => ['blocks' => ['A\\B']],
            'preflow' => ['translations' => ['x' => ['path' => 'lang']]],
            'branch-alias' => ['dev-main' => '1.x-dev'],
        ];
        $path = $this->installed([['name' => 'a/b', 'install-path' => '../a/b', 'extra' => $extra], ['name' => 'c/d', 'extra' => []]], 1_000_000);

        $packages = InstalledPackages::read($path, $this->dir . '/cache');

        $this->assertSame($extra, $packages[0]['extra']);
        $this->assertSame(['A\\B'], InstalledPackages::extra($packages[0], 'folio.blocks'));
        $this->assertNull(InstalledPackages::extra($packages[0], 'folio.nope'));
        $this->assertNull(InstalledPackages::extra($packages[0], 'folio.blocks.deeper'));
        $this->assertNull(InstalledPackages::extra($packages[1], 'folio.blocks'));
        $this->assertArrayNotHasKey('extra', $packages[1], 'an empty extra is not kept');
        foreach (['folio_blocks', 'folio_field_types', 'extension', 'translations'] as $key) {
            $this->assertArrayNotHasKey($key, $packages[0]);
        }
    }

    public function test_cache_of_older_format_is_reread(): void
    {
        $path = $this->installed([['name' => 'a/b', 'extra' => ['folio' => ['blocks' => ['A']]]]], 1_000_000);
        $cache = $this->dir . '/cache';
        mkdir($cache, 0777, true);
        $relative = '../installed.json';
        $file = $cache . '/installed-' . hash('xxh3', $relative) . '.php';
        $old = ['key' => $relative . '|1000000|' . filesize($path) . '|v2', 'packages' => [['name' => 'a/b', 'install-path' => null, 'folio_blocks' => ['STALE']]]];
        file_put_contents($file, '<?php return ' . var_export($old, true) . ';');

        $packages = InstalledPackages::read($path, $cache);

        $this->assertSame(['A'], InstalledPackages::extra($packages[0], 'folio.blocks'));
        $this->assertArrayNotHasKey('folio_blocks', $packages[0]);
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
        $this->assertSame(['g' => 'lang'], InstalledPackages::extra($packages[1], 'preflow.translations'));
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
