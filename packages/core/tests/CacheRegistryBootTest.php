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
        $app = Application::create($this->app('blade'));
        $app->boot();

        $source = $app->container()->get(CacheRegistry::class)->get('blade');

        $this->assertNotNull($source);
        $this->assertSame('shared temp folder', $source->stats()->note);
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
