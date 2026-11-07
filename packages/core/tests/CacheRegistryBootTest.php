<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/CacheSourceProvider.php';

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Cache\CacheRegistry;
use Preflow\Core\Tests\Fixtures\CacheSourceProvider;

/** The Application owns a CacheRegistry and registers the framework's own caches in it. */
final class CacheRegistryBootTest extends TestCase
{
    #[RunInSeparateProcess]
    public function test_the_json_query_cache_is_registered(): void
    {
        $app = Application::create($this->app('twig'));
        $app->boot();

        $source = $app->container()->get(CacheRegistry::class)->get('data.json');

        $this->assertNotNull($source);
        $this->assertSame('JSON storage query cache', $source->label());
    }

    #[RunInSeparateProcess]
    public function test_clearing_the_json_query_cache_clears_every_data_paths_cache(): void
    {
        $dir = $this->app('twig');
        $app = Application::create($dir);
        $app->boot();
        // The app's own data path and a second one (a test's own data) share the cache folder.
        foreach (['/storage/data', '/storage/other'] as $path) {
            $driver = new \Preflow\Data\Driver\JsonFileDriver($dir . $path, $dir . '/storage/cache/data');
            $driver->save('post', 'p1', ['uuid' => 'p1']);
            $driver->findMany('post', new \Preflow\Data\Query());
        }
        $this->assertCount(2, glob($dir . '/storage/cache/data/json-post-*.json'));

        $app->container()->get(CacheRegistry::class)->get('data.json')->clear();

        $this->assertSame([], glob($dir . '/storage/cache/data/json-*.json'));
    }

    #[RunInSeparateProcess]
    public function test_cache_clear_removes_json_caches_and_temp_files_but_keeps_generations(): void
    {
        $dir = $this->app('twig');
        $app = Application::create($dir);
        $app->boot();
        $cache = $dir . '/storage/cache/data';
        // A current data path, and a test's data path that was deleted long ago.
        $gone = new \Preflow\Data\Driver\JsonFileDriver($dir . '/vanished-tmp/data', $cache);
        $gone->save('post', 'p1', ['uuid' => 'p1']);
        $gone->findMany('post', new \Preflow\Data\Query());
        (new \Preflow\Data\Driver\JsonFileDriver($dir . '/storage/data', $cache))->save('post', 'p2', ['uuid' => 'p2']);
        $this->assertNotEmpty(glob($cache . '/json-*.gen'));
        file_put_contents($cache . '/json-post-deadbeef.json.tmp-ab12', '{}');
        $this->removeTree($dir . '/vanished-tmp');

        $console = new \Preflow\DevTools\Command\CacheClearCommand(static fn () => $app);
        ob_start();
        $code = $console->execute(['data.json']);
        ob_end_clean();

        $this->assertSame(0, $code);
        // Caches and temp files of every path key go (the vanished one's too) ...
        $this->assertSame([], glob($cache . '/json-*.json'));
        $this->assertSame([], glob($cache . '/json-*.tmp-*'));
        // ... the generation files stay: they guard a cache write racing a save.
        $gens = glob($cache . '/json-*.gen');
        $this->assertCount(2, $gens);
        $this->assertSame(0, $app->container()->get(CacheRegistry::class)->get('data.json')->stats()->entries);
    }

    private function removeTree(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    #[RunInSeparateProcess]
    public function test_a_provider_can_register_its_own_cache(): void
    {
        $app = Application::create($this->app('twig', withProvider: true));
        $app->boot();

        $this->assertNotNull($app->container()->get(CacheRegistry::class)->get('site.x'));
    }

    #[RunInSeparateProcess]
    public function test_blade_registers_its_compiled_templates(): void
    {
        $dir = $this->app('blade');
        $app = Application::create($dir);
        $app->boot();

        $source = $app->container()->get(CacheRegistry::class)->get('blade');

        $this->assertNotNull($source);
        $this->assertNull($source->stats()->note);
        // Blade compiles into the app's own storage/cache/blade, and the registry lists that folder.
        $this->assertDirectoryExists($dir . '/storage/cache/blade');
        file_put_contents($dir . '/storage/cache/blade/compiled.php', '<?php');
        $this->assertSame(1, $source->stats()->entries);
    }

    #[RunInSeparateProcess]
    public function test_a_relative_base_path_still_boots_with_json_and_blade_caches(): void
    {
        $dir = $this->app('blade');
        chdir(dirname($dir));
        $app = Application::create(basename($dir));
        $app->boot();

        $registry = $app->container()->get(CacheRegistry::class);
        $this->assertNotNull($registry->get('data.json'));
        $this->assertNotNull($registry->get('blade'));
    }

    #[RunInSeparateProcess]
    public function test_twig_registers_its_compiled_templates(): void
    {
        $dir = $this->app('twig');
        $app = Application::create($dir);
        $app->boot();

        $source = $app->container()->get(CacheRegistry::class)->get('twig');

        $this->assertNotNull($source);
        $this->assertSame('Compiled Twig templates', $source->label());
    }

    #[RunInSeparateProcess]
    public function test_cache_clear_empties_the_twig_cache(): void
    {
        $dir = $this->app('twig');
        $app = Application::create($dir);
        $app->boot();
        mkdir($dir . '/storage/cache/twig/ab', 0777, true);
        file_put_contents($dir . '/storage/cache/twig/ab/compiled.php', '<?php');

        $app->container()->get(CacheRegistry::class)->get('twig')->clear();

        $this->assertSame([], glob($dir . '/storage/cache/twig/*/*.php'));
    }

    #[RunInSeparateProcess]
    public function test_twig_compiles_into_a_folder_keyed_by_the_installed_packages(): void
    {
        $dir = $this->app('twig');
        mkdir($dir . '/app/pages', 0777, true);
        file_put_contents($dir . '/app/pages/index.twig', '<p>hi</p>');
        file_put_contents($dir . '/app/pages/about.twig', '<p>about</p>');
        mkdir($dir . '/vendor/composer', 0777, true);
        file_put_contents($dir . '/vendor/composer/installed.json', '{"packages":[]}');
        touch($dir . '/vendor/composer/installed.json', 1_700_000_000);

        // A template class already loaded in this process is not written again: each run renders its own page.
        $folders = function (string $page) use ($dir): array {
            $app = Application::create($dir);
            $app->boot();
            $app->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('GET', $page));

            return array_map('basename', glob($dir . '/storage/cache/twig/*', GLOB_ONLYDIR));
        };

        $first = $folders('/');
        $this->assertCount(1, $first);
        $this->assertNotEmpty(glob($dir . '/storage/cache/twig/' . $first[0] . '/*/*.php'));
        $this->assertSame($first, $folders('/')); // same packages, same folder

        // composer update: installed.json changes, templates compile into a new folder.
        file_put_contents($dir . '/vendor/composer/installed.json', '{"packages":[{"name":"x/y"}]}');
        $second = $folders('/about');
        $this->assertCount(2, $second);

        // cache:clear empties the parent, every key's compiled templates.
        $app = Application::create($dir);
        $app->boot();
        $app->container()->get(CacheRegistry::class)->get('twig')->clear();
        $this->assertSame([], glob($dir . '/storage/cache/twig/*/*/*.php'));
    }

    private function app(string $engine, bool $withProvider = false): string
    {
        $dir = sys_get_temp_dir() . '/cacheboot_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'{$engine}','key'=>'k'];\n");
        file_put_contents($dir . '/config/data.php', "<?php\nreturn ['default'=>'json','drivers'=>['json'=>['path'=>'{$dir}/storage/data']]];\n");
        if ($withProvider) {
            file_put_contents($dir . '/config/providers.php', "<?php\nreturn [\\" . CacheSourceProvider::class . "::class];\n");
        }

        return $dir;
    }
}
