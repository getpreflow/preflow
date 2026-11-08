<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Boot;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Boot\BootManifest;
use Preflow\Core\Boot\ManifestBuilder;
use Preflow\Core\Boot\ManifestFile;
use Preflow\Core\Cache\CacheRegistry;

/**
 * The boot manifest (`preflow optimize`): written atomically, used by a web boot with debug off
 * while .env, extensions.json and the .env values are unchanged; never by the console.
 */
final class BootManifestTest extends TestCase
{
    private string $dir;
    private string $env;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_manifest_' . bin2hex(random_bytes(4));
        $this->env = 'PF_MANIFEST_' . strtoupper(bin2hex(random_bytes(4)));
        $this->write('.env', "{$this->env}=Old\n");
        $this->write('config/app.php', "<?php\nreturn ['name' => getenv('{$this->env}') ?: 'none', 'debug' => 0, 'key' => 'manifest-test-key', 'locale' => 'en'];\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'url_strategy' => 'prefix'];\n");
        $this->write('config/site.php', "<?php\nreturn ['greeting' => 'hello', 'list' => [1, 2.5, true, null]];\n");
        $this->write('config/folio/extensions.json', "{\"enabled\": {}}\n");
        $this->write('app/pages/index.twig', 'HOME {{ t("site.hello") }}');
        $this->write('lang/en/site.php', "<?php\nreturn ['hello' => 'Hello'];\n");
        $this->write('lang/de/site.php', "<?php\nreturn ['hello' => 'Hallo'];\n");
        $this->write('vendor/composer/installed.json', '{"packages": []}');
        // The builder's boot reads the .env as a request would; the test process keeps what it loads.
        putenv($this->env);
        unset($_ENV[$this->env]);
    }

    protected function tearDown(): void
    {
        putenv($this->env);
        unset($_ENV[$this->env]);
        if (!is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_optimize_writes_the_manifest_and_boot_uses_it(): void
    {
        $result = (new ManifestBuilder($this->dir))->write();

        $this->assertTrue($result['written']);
        $this->assertFileExists($this->dir . '/bootstrap/cache/boot.php');
        $this->assertSame([], glob($this->dir . '/bootstrap/cache/*.tmp') ?: [], 'no temporary file left');
        $this->assertContains('site', $result['config']);
        $this->assertContains('core.components', $result['catalogs']);

        // The config files are gone: a web boot still has every value, from the manifest.
        rename($this->dir . '/config/app.php', $this->dir . '/config/app.moved');
        rename($this->dir . '/config/site.php', $this->dir . '/config/site.moved');
        rename($this->dir . '/config/i18n.php', $this->dir . '/config/i18n.moved');
        $app = $this->web();

        $this->assertTrue($app->bootManifest()->active());
        $this->assertSame('Old', $app->config()->get('app.name'));
        $this->assertSame(['greeting' => 'hello', 'list' => [1, 2.5, true, null]], $app->configFile('site'));
        $this->assertSame('HOME Hallo', (string) $app->handle((new Psr17Factory())->createServerRequest('GET', '/de'))->getBody());
    }

    public function test_output_is_identical_with_and_without_manifest(): void
    {
        $live = $this->render($this->web());
        (new ManifestBuilder($this->dir))->write();
        $app = $this->web();

        $this->assertTrue($app->bootManifest()->active());
        $this->assertSame($live, $this->render($app));
    }

    public function test_debug_mode_ignores_the_manifest(): void
    {
        (new ManifestBuilder($this->dir))->write();
        $data = include $this->dir . '/bootstrap/cache/boot.php';
        $data['config']['app']['debug'] = 1;
        ManifestFile::write($this->dir, $data);

        $this->assertFalse($this->web()->bootManifest()->active());

        // optimize in debug mode writes none and removes the one there is.
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Debug', 'debug' => 1, 'key' => 'manifest-test-key'];\n");
        $result = (new ManifestBuilder($this->dir))->write();
        $this->assertFalse($result['written']);
        $this->assertStringContainsString('APP_DEBUG', (string) $result['reason']);
        $this->assertFileDoesNotExist($this->dir . '/bootstrap/cache/boot.php');
    }

    public function test_cli_ignores_the_manifest(): void
    {
        (new ManifestBuilder($this->dir))->write();
        $this->write('config/site.php', "<?php\nreturn ['greeting' => 'changed on the server'];\n");

        $app = Application::create($this->dir);
        $app->setRunningInConsole(true);
        $app->boot();

        $this->assertFalse($app->bootManifest()->active());
        $this->assertSame(['greeting' => 'changed on the server'], $app->configFile('site'), 'a cron job sees live config');
    }

    public function test_a_changed_env_file_bypasses_the_manifest(): void
    {
        (new ManifestBuilder($this->dir))->write();
        $this->assertSame('Old', $this->web()->config()->get('app.name'));

        // Edited on the server after the deploy (a new process has none of the old values).
        putenv($this->env);
        unset($_ENV[$this->env]);
        $this->write('.env', "{$this->env}=Newer\n");
        touch($this->dir . '/.env', time() + 10);
        $app = $this->web();

        $this->assertFalse($app->bootManifest()->active());
        $this->assertSame('Newer', $app->config()->get('app.name'));
    }

    public function test_an_env_value_set_by_the_server_bypasses_the_manifest(): void
    {
        (new ManifestBuilder($this->dir))->write();
        putenv($this->env);
        putenv("{$this->env}=FromServer"); // SetEnv: EnvLoader never overrides it
        $app = $this->web();

        $this->assertFalse($app->bootManifest()->active());
        $this->assertSame('FromServer', $app->config()->get('app.name'));
    }

    public function test_a_changed_extensions_json_bypasses_the_manifest(): void
    {
        (new ManifestBuilder($this->dir))->write();
        $this->assertTrue($this->web()->bootManifest()->active());

        $this->write('config/folio/extensions.json', "{\"enabled\": {\"blog\": {\"disabled_parts\": []}}}\n");
        touch($this->dir . '/config/folio/extensions.json', time() + 10);

        $this->assertFalse($this->web()->bootManifest()->active());
    }

    public function test_cache_clear_removes_the_manifest(): void
    {
        (new ManifestBuilder($this->dir))->write();
        $app = $this->web();

        $cleared = $app->container()->get(CacheRegistry::class)->get('boot')->clear();

        $this->assertSame(1, $cleared->entries);
        $this->assertFileDoesNotExist($this->dir . '/bootstrap/cache/boot.php');
        $this->assertFalse($this->web()->bootManifest()->active());
    }

    public function test_optimize_check_reports_drift(): void
    {
        $builder = new ManifestBuilder($this->dir);
        $this->assertNull($builder->drift(), 'no manifest');

        $builder->write();
        $this->assertSame([], $builder->drift());

        $this->write('config/site.php', "<?php\nreturn ['greeting' => 'changed'];\n");
        touch($this->dir . '/config/site.php', time() + 10);
        $this->write('app/Components/.keep', '');
        $this->write('config/folio/extensions.json', "{\"enabled\": {\"blog\": {\"disabled_parts\": []}}}\n");
        touch($this->dir . '/config/folio/extensions.json', time() + 10);

        $drift = $builder->drift();
        $this->assertContains('config: changed', $drift);
        $this->assertContains('app/Components: added', $drift);
        $this->assertContains('config/folio/extensions.json: changed (checked per request: web requests boot live until preflow optimize)', $drift);
    }

    public function test_values_that_cannot_be_exported_stay_live(): void
    {
        $this->write('config/hooks.php', "<?php\nreturn ['on' => static fn (): string => 'live'];\n");

        $result = (new ManifestBuilder($this->dir))->write();
        $this->assertContains('config/hooks.php (holds closures or objects; read live)', $result['skipped']);
        $this->assertNotContains('hooks', $result['config']);

        $app = $this->web();
        $this->assertTrue($app->bootManifest()->active());
        $this->assertSame('live', $app->configFile('hooks')['on']());
    }

    public function test_a_corrupt_or_foreign_manifest_is_ignored(): void
    {
        $this->write('bootstrap/cache/boot.php', "<?php\nreturn ['version' => 999];\n");
        $this->assertFalse($this->web()->bootManifest()->active());

        $this->write('bootstrap/cache/boot.php', "<?php\nthrow new \\RuntimeException('broken');\n");
        touch($this->dir . '/bootstrap/cache/boot.php', time() + 10);
        $this->assertFalse($this->web()->bootManifest()->active());
    }

    public function test_remember_records_while_building_and_serves_from_the_manifest(): void
    {
        $recording = BootManifest::recording();
        $calls = 0;
        $this->assertSame(['a' => 1], $recording->remember('x', function () use (&$calls): array {
            $calls++;

            return ['a' => 1];
        }, ['/src']));
        $this->assertSame(['x' => ['a' => 1]], $recording->recorded()['catalogs']);
        $this->assertSame(['/src'], $recording->recorded()['sources']);

        $loaded = BootManifest::fromData(['catalogs' => ['x' => ['a' => 2]]]);
        $this->assertSame(['a' => 2], $loaded->remember('x', function () use (&$calls): array {
            $calls++;

            return [];
        }));
        $this->assertSame(1, $calls);
        $this->assertSame('live', BootManifest::off()->remember('x', static fn (): string => 'live'));
        $this->assertNull($loaded->routes(), 'routes: filled by the route cache');
    }

    private function web(): Application
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();

        return $app;
    }

    /** @return array<string, mixed> what a request sees: pages, config values */
    private function render(Application $app): array
    {
        $out = [];
        foreach (['/', '/de', '/no-such-page'] as $path) {
            $response = $app->handle((new Psr17Factory())->createServerRequest('GET', $path));
            $out[$path] = [$response->getStatusCode(), preg_replace('/nonce-[^\'"]+|nonce="[^"]+"/', 'NONCE', (string) $response->getBody())];
        }
        $out['config'] = $app->config()->all();
        $out['site'] = $app->configFile('site');

        return $out;
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }
}
