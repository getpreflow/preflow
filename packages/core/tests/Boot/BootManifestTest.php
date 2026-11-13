<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Boot;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Boot\BootManifest;
use Preflow\Core\Boot\ManifestBuilder;
use Preflow\Core\Boot\ManifestFile;
use Preflow\Core\Cache\CacheRegistry;

/**
 * The boot manifest (`preflow optimize`): written atomically, used by a web boot with debug off
 * while .env, the runtime files packages declare (extra.preflow.runtime) and the .env values are unchanged; never by the console.
 */
final class BootManifestTest extends TestCase
{
    private string $dir;
    private string $env;
    private string|false $appDebug = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_manifest_' . bin2hex(random_bytes(4));
        $this->env = 'PF_MANIFEST_' . strtoupper(bin2hex(random_bytes(4)));
        $this->appDebug = getenv('APP_DEBUG');
        $this->write('.env', "{$this->env}=Old\n");
        $this->write('config/app.php', "<?php\nreturn ['name' => getenv('{$this->env}') ?: 'none', 'debug' => 0, 'key' => 'manifest-test-key', 'locale' => 'en'];\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'url_strategy' => 'prefix'];\n");
        $this->write('config/site.php', "<?php\nreturn ['greeting' => 'hello', 'list' => [1, 2.5, true, null]];\n");
        $this->write('config/folio/extensions.json', "{\"enabled\": {}}\n");
        $this->write('app/pages/index.twig', 'HOME {{ t("site.hello") }}');
        $this->write('lang/en/site.php', "<?php\nreturn ['hello' => 'Hello'];\n");
        $this->write('lang/de/site.php', "<?php\nreturn ['hello' => 'Hallo'];\n");
        // A package that declares the extensions file as checked per request, as Folio's composer.json does.
        $this->write('vendor/composer/installed.json', '{"packages": [{"name": "acme/ext", "extra": {"preflow": {"runtime": ["config/folio/extensions.json"]}}}]}');
        // The builder's boot reads the .env as a request would; the test process keeps what it loads.
        putenv($this->env);
        unset($_ENV[$this->env]);
    }

    protected function tearDown(): void
    {
        putenv($this->appDebug === false ? 'APP_DEBUG' : 'APP_DEBUG=' . $this->appDebug);
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

    public function test_debug_set_by_the_environment_ignores_the_manifest(): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Site', 'debug' => (int) (getenv('APP_DEBUG') ?: 0), 'key' => 'manifest-test-key'];\n");
        putenv('APP_DEBUG'); // the .env doesn't name it
        (new ManifestBuilder($this->dir))->write();
        $this->assertTrue($this->web()->bootManifest()->active());

        putenv('APP_DEBUG=1'); // the web server sets it to debug a production error
        $app = $this->web();

        $this->assertFalse($app->bootManifest()->active());
        $this->assertSame(1, $app->config()->get('app.debug'));
    }

    public function test_debug_switched_on_in_config_on_the_server_ignores_the_manifest(): void
    {
        (new ManifestBuilder($this->dir))->write();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Site', 'debug' => 1, 'key' => 'manifest-test-key'];\n");
        touch($this->dir . '/config/app.php', time() + 10);

        $app = $this->web();

        $this->assertFalse($app->bootManifest()->active());
        $this->assertSame(1, $app->config()->get('app.debug'));
    }

    public function test_optimize_in_debug_mode_writes_no_manifest(): void
    {
        (new ManifestBuilder($this->dir))->write();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Debug', 'debug' => 1, 'key' => 'manifest-test-key'];\n");

        $result = (new ManifestBuilder($this->dir))->write();

        $this->assertFalse($result['written']);
        $this->assertStringContainsString('APP_DEBUG', (string) $result['reason']);
        $this->assertFileDoesNotExist($this->dir . '/bootstrap/cache/boot.php', 'the existing one is removed');
    }

    public function test_a_failed_build_removes_the_manifest(): void
    {
        (new ManifestBuilder($this->dir))->write();
        $this->write('config/providers.php', "<?php\nthrow new \\RuntimeException('broken provider list');\n");

        try {
            (new ManifestBuilder($this->dir))->write();
            $this->fail('the build should fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('broken provider list', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir . '/bootstrap/cache/boot.php');
    }

    public function test_a_failed_write_removes_the_manifest(): void
    {
        (new ManifestBuilder($this->dir))->write();
        // Builds, but does not load back as written: the write fails after a good build.
        $this->write('config/lossy.php', "<?php\nreturn ['value' => new \\" . LossyExport::class . "('kept')];\n");

        try {
            (new ManifestBuilder($this->dir))->write();
            $this->fail('the write should fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not load back', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir . '/bootstrap/cache/boot.php', 'never the manifest of the code before');
        $this->assertSame([], glob($this->dir . '/bootstrap/cache/*.tmp'), 'no temporary file left');
    }

    public function test_an_in_place_composer_install_bypasses_the_manifest(): void
    {
        (new ManifestBuilder($this->dir))->write();
        $this->write('vendor/composer/installed.json', '{"packages": [{"name": "acme/new"}]}');
        touch($this->dir . '/vendor/composer/installed.json', time() + 10);

        $this->assertFalse($this->web()->bootManifest()->active());
    }

    public function test_the_manifest_is_no_more_readable_than_the_env(): void
    {
        chmod($this->dir . '/.env', 0600);
        (new ManifestBuilder($this->dir))->write();

        $this->assertSame(0600, fileperms($this->dir . '/bootstrap/cache/boot.php') & 0777);
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

    public function test_package_runtime_files_are_checked_per_request(): void
    {
        $this->installedWithRuntime('["config/x/state.json"]');
        $this->write('config/x/state.json', '{"a":1}');
        (new ManifestBuilder($this->dir))->write();
        $this->assertTrue($this->web()->bootManifest()->active());

        $this->write('config/x/state.json', '{"a":1,"b":2}');

        $this->assertFalse($this->web()->bootManifest()->active());
    }

    public function test_missing_runtime_file_then_created_makes_manifest_stale(): void
    {
        $this->installedWithRuntime('["config/x/state.json"]');
        (new ManifestBuilder($this->dir))->write();
        $this->assertTrue($this->web()->bootManifest()->active());

        $this->write('config/x/state.json', '{}');

        $this->assertFalse($this->web()->bootManifest()->active());
    }

    public function test_package_runtime_files_are_listed_as_sources_and_drift(): void
    {
        $this->installedWithRuntime('["config/x/state.json", "config/x/state.json"]');
        $builder = new ManifestBuilder($this->dir);
        $data = $builder->build();

        $this->assertSame(['.env', 'vendor/composer/installed.json', 'config/x/state.json', 'env'], array_keys($data['runtime']));
        $this->assertArrayHasKey('config/x/state.json', $data['sources']);

        $builder->write();
        $this->write('config/x/state.json', '{}');
        $this->assertContains('config/x/state.json: added (checked per request: web requests boot live until preflow optimize)', $builder->drift());
    }

    public function test_manifest_without_a_core_runtime_source_is_no_manifest(): void
    {
        $builder = new ManifestBuilder($this->dir);
        $builder->write();
        $file = $this->dir . '/bootstrap/cache/boot.php';
        $data = include $file;
        unset($data['runtime']['.env']);
        file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }

        $this->assertNull(ManifestFile::load($this->dir));
        $this->assertSame(['the manifest lacks the checked source .env; run preflow optimize'], $builder->drift());
    }

    public function test_manifest_of_older_version_is_ignored(): void
    {
        (new ManifestBuilder($this->dir))->write();
        $file = $this->dir . '/bootstrap/cache/boot.php';
        $data = include $file;
        $data['version'] = 1;
        file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }

        $this->assertNull(ManifestFile::load($this->dir));
    }

    #[DataProvider('invalidRuntime')]
    public function test_invalid_runtime_declarations_throw_naming_the_package(string $json): void
    {
        $this->installedWithRuntime($json);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Package acme/pkg: extra.preflow.runtime');
        (new ManifestBuilder($this->dir))->build();
    }

    /** @return array<string, array{string}> */
    public static function invalidRuntime(): array
    {
        return [
            'not a list' => ['{"a": "b"}'],
            'a string' => ['"config/x"'],
            'absolute' => ['["/etc/passwd"]'],
            'parent segment' => ['["config/../../x"]'],
            'empty' => ['[""]'],
            'not a string' => ['[1]'],
            'env, which is the key of the .env check' => ['["env"]'],
        ];
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

    public function test_optimize_check_lists_each_drifted_source_once(): void
    {
        $builder = new ManifestBuilder($this->dir);
        $builder->write();
        // Signed sources that boot also checks per request.
        $this->write('vendor/composer/installed.json', '{"packages": [{"name": "acme/new"}]}');
        touch($this->dir . '/vendor/composer/installed.json', time() + 10);
        $this->write('.env', "{$this->env}=Old\nPF_OTHER=1\n");
        touch($this->dir . '/.env', time() + 10);

        $drift = $builder->drift() ?? [];
        foreach (['vendor/composer/installed.json', '.env'] as $source) {
            $lines = array_values(array_filter($drift, static fn (string $line): bool => str_starts_with($line, $source . ':')));
            $this->assertSame(["{$source}: changed (checked per request: web requests boot live until preflow optimize)"], $lines, $source);
        }
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

    public function test_an_object_used_in_several_places_is_written(): void
    {
        $shared = SharedManifestValue::__set_state(['name' => 'employers']);

        ManifestFile::write($this->dir, ['first' => [$shared], 'second' => [$shared]]);

        $back = include $this->dir . '/bootstrap/cache/boot.php';
        $this->assertEquals(['first' => [$shared], 'second' => [$shared]], $back);
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
    }

    public function test_route_sets_are_recorded_while_building_and_served_from_the_manifest(): void
    {
        $row = ['pattern' => '/a', 'handler' => 'A@b', 'method' => 'GET', 'mode' => 'action', 'middleware' => [], 'paramNames' => [], 'regex' => '', 'isCatchAll' => false, 'csrfExempt' => false];
        $recording = BootManifest::recording();
        $this->assertSame([$row], $recording->routes('app', static fn (): array => [$row], ['/pages']));
        $closure = static fn () => null;
        $recording->routes('pkg', static fn (): array => [['middleware' => [$closure]] + $row]);
        $this->assertSame(['app' => [$row]], $recording->recorded()['routes'], 'a set with closures is not frozen');
        $this->assertSame(['/pages'], $recording->recorded()['sources']);
        $this->assertContains('routes pkg (not exportable; compiled live)', $recording->recorded()['skipped']);

        $loaded = BootManifest::fromData(['routes' => ['app' => [$row]]]);
        $this->assertSame([$row], $loaded->routes('app', fn (): array => $this->fail('not compiled with a manifest')));
        $this->assertSame([], $loaded->routes('other', static fn (): array => []), 'a set the manifest lacks is compiled live');
        $this->assertSame([], BootManifest::off()->routes('app', static fn (): array => []));
    }

    public function test_app_routes_come_compiled_from_the_manifest(): void
    {
        $result = (new ManifestBuilder($this->dir))->write();
        $this->assertContains('app', $result['routes']);

        // A page added after optimize (pages change only on deploy): the manifest boot doesn't scan for it.
        $this->write('app/pages/added.twig', 'ADDED');
        $app = $this->web();
        $router = $app->container()->get(\Preflow\Core\Routing\RouterInterface::class);
        $this->assertSame(200, $app->handle((new Psr17Factory())->createServerRequest('GET', '/'))->getStatusCode());
        $this->assertSame(404, $app->handle((new Psr17Factory())->createServerRequest('GET', '/added'))->getStatusCode());
        $this->assertSame([], $router->liveSets(), 'no route set compiled');
        $this->assertContains('app/pages: changed', (new ManifestBuilder($this->dir))->drift() ?? []);

        // Live boots (the console, or after the next optimize) see it.
        $console = Application::create($this->dir);
        $console->setRunningInConsole(true);
        $console->boot();
        $this->assertSame(200, $console->handle((new Psr17Factory())->createServerRequest('GET', '/added'))->getStatusCode());
        $this->assertSame(['app'], $console->container()->get(\Preflow\Core\Routing\RouterInterface::class)->liveSets());
    }

    private function installedWithRuntime(string $runtimeJson): void
    {
        $this->write('vendor/composer/installed.json', '{"packages": [{"name": "acme/pkg", "extra": {"preflow": {"runtime": ' . $runtimeJson . '}}}]}');
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

/** Exportable (it has __set_state), but loads back without its value: a write that fails after the build. */
final class LossyExport
{
    public function __construct(public ?string $value = null) {}

    /** @param array<string, mixed> $state */
    public static function __set_state(array $state): self
    {
        return new self();
    }
}

final class SharedManifestValue
{
    public string $name = '';

    /** @param array{name: string} $state */
    public static function __set_state(array $state): self
    {
        $value = new self();
        $value->name = $state['name'];

        return $value;
    }
}
