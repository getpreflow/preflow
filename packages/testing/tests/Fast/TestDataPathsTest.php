<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fast;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Cache\CacheRegistry;
use Preflow\Testing\Fast\TestDataPaths;

final class TestDataPathsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/preflow_datapaths_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/app/config', 0777, true);
        file_put_contents($this->root . '/app/config/data.php', "<?php return ['default' => 'json', 'drivers' => ['json' => ['path' => getenv('DATA_PATH')]]];");
    }

    protected function tearDown(): void
    {
        TestDataPaths::release();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    #[RunInSeparateProcess]
    public function test_a_test_data_path_keeps_its_json_cache_under_its_temp_root(): void
    {
        [$data, $cache] = TestDataPaths::use($this->root . '/tmp');
        $app = Application::create($this->root . '/app');
        $app->boot();

        $driver = $app->container()->get(\Preflow\Data\DataManager::class)->driver('json');
        $driver->save('post', 'p1', ['uuid' => 'p1']);
        $driver->findMany('post', new \Preflow\Data\Query());

        $this->assertSame($cache, $driver->cacheDir());
        $this->assertNotEmpty(glob($cache . '/json-post-*.json'));
        $this->assertDirectoryDoesNotExist($this->root . '/app/storage/cache/data');

        // cache:clear empties the folder the app really uses (the tiny .gen generation files stay).
        $app->container()->get(CacheRegistry::class)->get('data.json')->clear();
        $this->assertSame([], glob($cache . '/json-*.json'));
        $this->assertNotEmpty(glob($cache . '/json-post-*.gen'));
        $this->assertSame($data, getenv('DATA_PATH'));
    }

    public function test_release_unsets_both_variables(): void
    {
        TestDataPaths::use($this->root . '/tmp');
        TestDataPaths::release();

        $this->assertFalse(getenv('DATA_PATH'));
        $this->assertFalse(getenv('DATA_CACHE_PATH'));
    }
}
