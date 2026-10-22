<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\ExtensionRegistry;

/**
 * Project fixtures live in Fixtures/extensions/app/{Dir} (namespace App\Folio\{Dir}); each test
 * copies the ones it needs into a temp app's app/Folio and autoloads App\Folio\ from there.
 * The package fixture (Fixtures/extensions/Shop) autoloads through the tests' PSR-4 namespace.
 */
final class DiscoveryTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/extensions';
    private const SHOP = 'Preflow\\Folio\\Tests\\Fixtures\\extensions\\Shop\\Extension';

    private string $dir;
    private \Closure $autoload;
    private string|false $errorLog;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_ext_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/app/Folio', 0777, true);
        $root = $this->dir . '/app/Folio/';
        $this->autoload = static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\Folio\\')) {
                $file = $root . str_replace('\\', '/', substr($class, strlen('App\\Folio\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoload);
        $this->errorLog = ini_get('error_log');
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        ini_set('error_log', $this->errorLog === false ? '' : $this->errorLog);
        putenv('APP_DEBUG');
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_discovers_project_extension_and_parts(): void
    {
        $this->project('Members');
        $this->write('app/Folio/Overrides/Extension.php', "<?php\nthrow new \\LogicException('Overrides must be skipped');\n");
        $this->write('app/Folio/Notes/readme.txt', 'a folder without Extension.php is not an extension');

        $registry = ExtensionRegistry::discover($this->dir, debug: true);

        $this->assertCount(1, $registry->all());
        $members = $registry->get('members');
        $this->assertSame('members', $members->name);
        $this->assertSame('Members', $members->label);
        $this->assertSame('Member accounts', $members->description);
        $this->assertSame('ui-users', $members->icon);
        $this->assertSame('1.2.0', $members->version);
        $this->assertSame('App\\Folio\\Members\\Extension', $members->class);
        $this->assertSame($this->dir . '/app/Folio/Members', $members->folder);
        $this->assertSame([
            ['id' => 'model:member', 'kind' => 'model', 'label' => 'Members'],
            ['id' => 'screen:approvals', 'kind' => 'screen', 'class' => 'App\\Folio\\Members\\Screens\\Approvals', 'label' => 'Approvals'],
            ['id' => 'type:post', 'kind' => 'type', 'class' => 'App\\Folio\\Members\\Types\\PostTweaks', 'label' => 'post'],
            ['id' => 'settings', 'kind' => 'settings', 'label' => 'Settings'],
        ], $members->parts);
    }

    public function test_discovers_package_extension_from_installed_json(): void
    {
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'acme/other', 'extra' => ['branch-alias' => []]],
            ['name' => 'acme/shop', 'extra' => ['folio' => ['extension' => self::SHOP]]],
        ]]));

        $shop = ExtensionRegistry::discover($this->dir, debug: true)->get('shop');

        $this->assertSame(self::SHOP, $shop->class);
        $this->assertSame(realpath(self::FIXTURES . '/Shop'), $shop->folder);
        $this->assertSame([
            ['id' => 'screen:orders', 'kind' => 'screen', 'class' => 'Preflow\\Folio\\Tests\\Fixtures\\extensions\\Shop\\Screens\\Orders', 'label' => 'orders'],
        ], $shop->parts);

        // Composer 1 wrote a top-level list.
        $this->write('vendor/composer/installed.json', (string) json_encode([
            ['name' => 'acme/shop', 'extra' => ['folio' => ['extension' => self::SHOP]]],
        ]));
        $this->assertSame(['shop'], array_map(fn ($d) => $d->name, ExtensionRegistry::discover($this->dir, debug: true)->all()));
    }

    public function test_missing_attribute_throws(): void
    {
        $this->project('NoAttr');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('#[FolioExtension]');
        ExtensionRegistry::discover($this->dir, debug: false);
    }

    public function test_wrong_class_name_throws(): void
    {
        $this->project('WrongName');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('App\\Folio\\WrongName\\Extension');
        ExtensionRegistry::discover($this->dir, debug: false);
    }

    public function test_duplicate_name_throws(): void
    {
        $this->project('DupA', 'DupB');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('"dup"');
        ExtensionRegistry::discover($this->dir, debug: false);
    }

    public function test_unknown_enabled_name_throws_in_debug_logs_in_production(): void
    {
        $this->project('Members');
        $this->write('config/folio/extensions.json', '{"enabled": {"ghost": {"disabled_parts": []}, "members": {"disabled_parts": ["screen:nope"]}}}');

        try {
            ExtensionRegistry::discover($this->dir, debug: true);
            $this->fail('An unknown extension in the file must throw in debug');
        } catch (ExtensionException $e) {
            $this->assertStringContainsString('"ghost"', $e->getMessage());
        }

        $log = $this->dir . '/error.log';
        ini_set('error_log', $log);
        $registry = ExtensionRegistry::discover($this->dir, debug: false);

        $this->assertSame(['members'], array_map(fn ($d) => $d->name, $registry->enabled()));
        $this->assertFalse($registry->isEnabled('ghost'));
        $logged = (string) file_get_contents($log);
        $this->assertStringContainsString('"ghost"', $logged);
        $this->assertStringContainsString('"screen:nope"', $logged);
    }

    public function test_unknown_part_throws_in_debug(): void
    {
        $this->project('Members');
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": ["screen:nope"]}}}');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('"screen:nope"');
        ExtensionRegistry::discover($this->dir, debug: true);
    }

    public function test_no_file_means_nothing_enabled(): void
    {
        $this->project('Members');

        $registry = ExtensionRegistry::discover($this->dir, debug: true);

        $this->assertSame([], $registry->enabled());
        $this->assertFalse($registry->isEnabled('members'));
        $this->assertFalse($registry->partEnabled('members', 'model:member'));
        $this->assertSame([], $registry->enabledModels());
        $this->expectException(ExtensionException::class);
        $registry->get('ghost');
    }

    public function test_disabled_part_reported(): void
    {
        $this->project('Members');
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": ["screen:approvals"]}}}');

        $registry = ExtensionRegistry::discover($this->dir, debug: true);

        $this->assertTrue($registry->isEnabled('members'));
        $this->assertFalse($registry->partEnabled('members', 'screen:approvals'));
        $this->assertTrue($registry->partEnabled('members', 'type:post'));
        $this->assertTrue($registry->partEnabled('members', 'model:member'));
        $this->assertTrue($registry->partEnabled('members', 'settings'));
        $this->assertFalse($registry->partEnabled('members', 'screen:unknown'));
    }

    public function test_cache_invalidates_on_file_change(): void
    {
        $this->project('Members');
        $file = $this->dir . '/config/folio/extensions.json';
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": []}}}');
        $cache = $this->dir . '/storage/cache/folio-extensions.cache';

        $this->assertTrue(ExtensionRegistry::discover($this->dir, debug: false, cacheFile: $cache)->isEnabled('members'));
        $this->assertFileExists($cache);
        $this->assertTrue(ExtensionRegistry::discover($this->dir, debug: false, cacheFile: $cache)->isEnabled('members'));

        $this->write('config/folio/extensions.json', '{"enabled": {}}');
        touch($file, time() + 10);

        $this->assertFalse(ExtensionRegistry::discover($this->dir, debug: false, cacheFile: $cache)->isEnabled('members'));
    }

    public function test_no_extensions_writes_nothing(): void
    {
        $cache = $this->dir . '/storage/cache/folio-extensions.cache';

        $registry = ExtensionRegistry::discover($this->dir, debug: false, cacheFile: $cache);

        $this->assertSame([], $registry->all());
        $this->assertDirectoryDoesNotExist($this->dir . '/storage');
        $this->assertDirectoryDoesNotExist($this->dir . '/config');
    }

    public function test_enabled_model_registered_in_catalog_and_registry(): void
    {
        $this->project('Members');
        $this->appConfig();
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": []}}}');

        $container = $this->boot()->container();

        $this->assertContains('member', array_map(fn ($l) => $l->key, $container->get(TypeCatalog::class)->all()));
        $this->assertSame('member', $container->get(TypeRegistry::class)->get('member')->key);
        $this->assertTrue($container->get(ExtensionRegistry::class)->isEnabled('members'));
        // The extension's templates/ is the Twig namespace @members.
        $this->assertSame("<p>Hi</p>\n", $container->get(\Preflow\View\TemplateEngineInterface::class)->render('@members/hello.twig', ['greeting' => 'Hi']));
    }

    public function test_disabled_model_part_not_registered(): void
    {
        $this->project('Members');
        $this->appConfig();
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": ["model:member"]}}}');

        $container = $this->boot()->container();

        $this->assertNotContains('member', array_map(fn ($l) => $l->key, $container->get(TypeCatalog::class)->all()));
        $this->assertFalse($container->get(TypeRegistry::class)->has('member'));
    }

    public function test_extension_model_clashing_with_model_file_throws(): void
    {
        $this->project('Members');
        $this->appConfig();
        $this->write('config/models/member.json', '{"key": "member", "storage": "json", "fields": {}}');
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": []}}}');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('Extension model "member"');
        $this->boot();
    }

    private function project(string ...$names): void
    {
        foreach ($names as $name) {
            $source = self::FIXTURES . '/app/' . $name;
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
            foreach ($rii as $f) {
                $this->write('app/Folio/' . $name . substr($f->getPathname(), strlen($source)), (string) file_get_contents($f->getPathname()));
            }
        }
    }

    private function appConfig(): void
    {
        putenv('APP_DEBUG=1');
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Ext', 'debug' => 1, 'engine' => 'twig', 'key' => 'folio-extension-test-key'];\n");
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        @mkdir($this->dir . '/config/models', 0777, true);
        @mkdir($this->dir . '/storage/data', 0777, true);
    }

    private function boot(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }
}
