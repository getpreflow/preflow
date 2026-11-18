<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Boot\BootManifest;
use Preflow\Core\Config\Config;
use Preflow\Core\Container\Attributes\Config as ConfigValue;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;

final class AppConfigFileReader
{
    public function __construct(
        #[ConfigValue('foo.bar')] public readonly string $bar,
        #[ConfigValue('foo.missing', default: 'fallback')] public readonly string $missing,
    ) {}
}

/** The recipe the docs show: hand an app's own config/<name>.php to Config in a provider's register(). */
final class AppConfigFileProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $app = $container->get(Application::class);
        $container->get(Config::class)->set('foo', $app->configFile('foo') ?? []);
    }
}

final class AppConfigFileRecipeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cfgrecipe_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");
        file_put_contents($this->dir . '/config/foo.php', "<?php\nreturn ['bar' => 'baz'];\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/config/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function test_config_holds_only_app_image_and_view(): void
    {
        $app = Application::create($this->dir);

        $this->assertNull($app->container()->get(Config::class)->get('foo'));
        $this->assertSame(['bar' => 'baz'], $app->configFile('foo'));
    }

    public function test_a_provider_puts_the_file_into_config(): void
    {
        $app = Application::create($this->dir);
        $app->registerProvider(new AppConfigFileProvider());
        $app->boot();

        $reader = $app->container()->get(AppConfigFileReader::class);
        $this->assertSame('baz', $reader->bar);
        $this->assertSame('fallback', $reader->missing);
    }

    public function test_the_recipe_works_with_a_boot_manifest(): void
    {
        $app = Application::create($this->dir);
        $app->useBootManifest(BootManifest::fromData(['config' => ['foo' => ['bar' => 'from-manifest']]]));
        $app->registerProvider(new AppConfigFileProvider());
        $app->boot();

        $this->assertSame('from-manifest', $app->container()->get(AppConfigFileReader::class)->bar);
    }
}
