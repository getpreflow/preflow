<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Cache\DirectoryCache;

final class DirectoryCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/dircache_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/cache/sub', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function fill(): void
    {
        file_put_contents($this->root . '/cache/a.txt', 'abc');
        file_put_contents($this->root . '/cache/sub/b.txt', 'abcde');
        file_put_contents($this->root . '/cache/.gitkeep', '');
    }

    public function test_stats_count_files_at_every_depth_but_not_dotfiles(): void
    {
        $this->fill();
        $stats = (new DirectoryCache('k', 'Label', $this->root . '/cache'))->stats();

        $this->assertSame(2, $stats->entries);
        $this->assertSame(8, $stats->bytes);
    }

    public function test_clear_removes_files_and_subfolders_but_keeps_the_folder_and_gitkeep(): void
    {
        $this->fill();
        $cleared = (new DirectoryCache('k', 'Label', $this->root . '/cache'))->clear();

        $this->assertSame(2, $cleared->entries);
        $this->assertSame(8, $cleared->bytes);
        $this->assertDirectoryExists($this->root . '/cache');
        $this->assertFileExists($this->root . '/cache/.gitkeep');
        $this->assertDirectoryDoesNotExist($this->root . '/cache/sub');
    }

    public function test_a_missing_folder_is_empty(): void
    {
        $cache = new DirectoryCache('k', 'Label', $this->root . '/nope');

        $this->assertSame(0, $cache->stats()->entries);
        $this->assertSame(0, $cache->stats()->bytes);
        $this->assertSame(0, $cache->clear()->entries);
        $this->assertSame(0, $cache->clear()->bytes);
    }

    public function test_a_pattern_limits_stats_and_clear_to_matching_files_at_every_depth(): void
    {
        file_put_contents($this->root . '/cache/json-post.json', '12');
        file_put_contents($this->root . '/cache/sub/json-page.json', '123');
        file_put_contents($this->root . '/cache/other.txt', 'x');
        $cache = new DirectoryCache('k', 'Label', $this->root . '/cache', pattern: 'json-*.json');

        $this->assertSame(2, $cache->stats()->entries);
        $this->assertSame(5, $cache->stats()->bytes);

        $cleared = $cache->clear();

        $this->assertSame(2, $cleared->entries);
        $this->assertSame(5, $cleared->bytes);
        $this->assertFileExists($this->root . '/cache/other.txt');
        $this->assertFileDoesNotExist($this->root . '/cache/json-post.json');
    }

    public function test_symlinks_are_not_counted_and_never_followed(): void
    {
        mkdir($this->root . '/outside');
        file_put_contents($this->root . '/outside/x.txt', 'keep');
        symlink($this->root . '/outside', $this->root . '/cache/link');
        $cache = new DirectoryCache('k', 'Label', $this->root . '/cache');

        $this->assertSame(0, $cache->stats()->entries);

        $cache->clear();

        $this->assertFalse(is_link($this->root . '/cache/link'));
        $this->assertFileExists($this->root . '/outside/x.txt');
    }

    public function test_the_note_is_passed_to_the_stats(): void
    {
        $cache = new DirectoryCache('k', 'Label', $this->root . '/cache', note: 'shared temp folder');

        $this->assertSame('shared temp folder', $cache->stats()->note);
        $this->assertSame('k', $cache->key());
        $this->assertSame('Label', $cache->label());
    }
}
