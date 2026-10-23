<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Core\Application;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\ExtensionsFile;

/**
 * A model part with "opt_in": true is off until the site lists it under "enabled_parts":
 * enabling the extension alone changes nothing for it (no type, no permissions), and the
 * extension's type part for that model follows it. Fixture: Fixtures/extensions/app/Crew
 * (model crew opt-in, model note normal, Types/CrewRules on crew).
 */
final class OptInPartTest extends TestCase
{
    private string $dir;
    private \Closure $autoload;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_optin_' . bin2hex(random_bytes(4));
        $source = __DIR__ . '/../Fixtures/extensions/app/Crew';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $this->write('app/Folio/Crew' . substr($f->getPathname(), strlen($source)), (string) file_get_contents($f->getPathname()));
        }
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
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
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

    public function test_opt_in_part_is_off_while_the_extension_is_on(): void
    {
        $this->write('config/folio/extensions.json', '{"enabled": {"crew": {"disabled_parts": []}}}');

        $registry = ExtensionRegistry::discover($this->dir, debug: true);

        $this->assertContains(['id' => 'model:crew', 'kind' => 'model', 'label' => 'Crew', 'opt_in' => true], $registry->get('crew')->parts);
        $this->assertTrue($registry->partEnabled('crew', 'model:note'));
        $this->assertFalse($registry->partEnabled('crew', 'model:crew'));
        $this->assertFalse($registry->partEnabled('crew', 'type:crew'), 'the type part follows its opt-in model');
        $this->assertSame(['note'], array_column($registry->enabledModels(), 'key'));
    }

    public function test_listed_under_enabled_parts_it_is_on(): void
    {
        $this->write('config/folio/extensions.json', '{"enabled": {"crew": {"disabled_parts": [], "enabled_parts": ["model:crew"]}}}');

        $registry = ExtensionRegistry::discover($this->dir, debug: true);

        $this->assertTrue($registry->partEnabled('crew', 'model:crew'));
        $this->assertTrue($registry->partEnabled('crew', 'type:crew'));
        $models = $registry->enabledModels();
        $this->assertSame(['crew', 'note'], array_column($models, 'key'));
        $this->assertArrayNotHasKey('opt_in', $models[0], 'the flag is not part of the type schema');
    }

    public function test_enabled_parts_naming_a_normal_part_throws_in_debug(): void
    {
        $this->write('config/folio/extensions.json', '{"enabled": {"crew": {"disabled_parts": [], "enabled_parts": ["model:note"]}}}');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('"model:note"');
        ExtensionRegistry::discover($this->dir, debug: true);
    }

    public function test_cache_keeps_opted_in_parts(): void
    {
        $this->write('config/folio/extensions.json', '{"enabled": {"crew": {"disabled_parts": [], "enabled_parts": ["model:crew"]}}}');
        $cache = $this->dir . '/storage/cache/folio-extensions.cache';

        ExtensionRegistry::discover($this->dir, debug: false, cacheFile: $cache);
        $this->assertFileExists($cache);

        $this->assertTrue(ExtensionRegistry::discover($this->dir, debug: false, cacheFile: $cache)->partEnabled('crew', 'model:crew'));
    }

    public function test_file_enables_and_disables_an_opt_in_part(): void
    {
        $this->write('config/folio/extensions.json', '{"enabled": {"crew": {"disabled_parts": []}}}');
        $definition = ExtensionRegistry::discover($this->dir, debug: true)->get('crew');
        $file = new ExtensionsFile($this->dir . '/config/folio/extensions.json');

        $file->enable($definition, 'model:crew');
        $this->assertSame(['crew' => ['disabled_parts' => [], 'enabled_parts' => ['model:crew']]], $file->read());
        $this->assertTrue(ExtensionRegistry::discover($this->dir, debug: true)->partEnabled('crew', 'model:crew'));

        $file->disable($definition, 'model:crew');
        $this->assertSame(['crew' => ['disabled_parts' => []]], $file->read());
        $this->assertSame("{\n    \"enabled\": {\n        \"crew\": {\n            \"disabled_parts\": []\n        }\n    }\n}\n", (string) file_get_contents($file->path()));
    }

    #[RunInSeparateProcess]
    public function test_not_opted_in_no_type_and_no_permissions(): void
    {
        $this->appConfig('{"enabled": {"crew": {"disabled_parts": []}}}');

        $container = $this->boot()->container();

        $this->assertFalse($container->get(TypeCatalog::class)->has('crew'));
        $this->assertFalse($container->get(TypeRegistry::class)->has('crew'));
        $this->assertTrue($container->get(TypeCatalog::class)->has('note'));
        $registry = $container->get(Access::class)->registry();
        $this->assertFalse($registry->isDeclared('crew.view'), 'an opt-in part not chosen declares nothing');
        $this->assertTrue($registry->isDeclared('note.view'));
    }

    #[RunInSeparateProcess]
    public function test_opted_in_type_and_permissions(): void
    {
        $this->appConfig('{"enabled": {"crew": {"disabled_parts": [], "enabled_parts": ["model:crew"]}}}');

        $container = $this->boot()->container();

        $this->assertTrue($container->get(TypeCatalog::class)->has('crew'));
        $registry = $container->get(Access::class)->registry();
        $this->assertTrue($registry->isDeclared('crew.edit'));
        $this->assertNull($registry->disabledReason('crew.edit'));
    }

    private function appConfig(string $extensions): void
    {
        putenv('APP_DEBUG=1');
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Ext', 'debug' => 1, 'engine' => 'twig', 'key' => 'folio-optin-test-key'];\n");
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => []]],
            'session' => ['cookie' => 'folio_optin_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/folio/extensions.json', $extensions);
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
