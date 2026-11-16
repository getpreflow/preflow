<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Http\LocaleUrls;

/**
 * Pins what packages rely on since 0.78: LocaleUrls is bound before any provider from
 * config/providers.php registers, and before every provider boots (also one the site
 * registered itself before boot()), with or without config/i18n.php. Members' Links and the
 * t()/locale_url() helpers take it without a fallback.
 */
final class LocaleUrlsProviderBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        LocaleUrlsProbeProvider::$seen = [];
        $this->dir = sys_get_temp_dir() . '/locale_urls_providers_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','locale'=>'en'];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [\\" . LocaleUrlsProbeProvider::class . "::class];\n");
    }

    protected function tearDown(): void
    {
        foreach (['config/app.php', 'config/providers.php', 'config/i18n.php'] as $file) {
            @unlink($this->dir . '/' . $file);
        }
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function test_bound_when_config_providers_register_and_boot(): void
    {
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'url_strategy'=>'prefix','prefix_default'=>true];\n");
        $app = Application::create($this->dir);
        $app->boot();

        $this->assertSame(['register' => true, 'boot' => true], LocaleUrlsProbeProvider::$seen);
        $this->assertSame('/en', $app->container()->get(LocaleUrls::class)->prefix('en'));
    }

    public function test_bound_without_config_i18n(): void
    {
        $app = Application::create($this->dir);
        $app->boot();

        $this->assertSame(['register' => true, 'boot' => true], LocaleUrlsProbeProvider::$seen);
        $this->assertSame(['en'], $app->container()->get(LocaleUrls::class)->locales);
    }

    public function test_bound_when_a_provider_registered_before_boot_boots(): void
    {
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [];\n");
        $app = Application::create($this->dir);
        $app->registerProvider(new LocaleUrlsProbeProvider());
        $app->boot();

        $this->assertTrue(LocaleUrlsProbeProvider::$seen['boot'] ?? null);
    }
}

/** Records whether LocaleUrls is bound when it registers and when it boots. */
final class LocaleUrlsProbeProvider extends ServiceProvider
{
    /** @var array<string, bool> */
    public static array $seen = [];

    public function register(Container $container): void
    {
        self::$seen['register'] = $container->bound(LocaleUrls::class);
    }

    public function boot(Container $container): void
    {
        self::$seen['boot'] = $container->bound(LocaleUrls::class);
    }
}
