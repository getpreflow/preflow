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
    public function test_twig_registers_nothing(): void
    {
        $app = Application::create($this->app('twig'));
        $app->boot();

        $this->assertNull($app->container()->get(CacheRegistry::class)->get('twig'));
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
