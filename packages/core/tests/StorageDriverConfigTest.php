<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/Pluggable/MemoryDriver.php';
require_once __DIR__ . '/Fixtures/Pluggable/MemoryNote.php';
require_once __DIR__ . '/Fixtures/Pluggable/PhtmlEngine.php';
require_once __DIR__ . '/Fixtures/Pluggable/SignalDriver.php';
require_once __DIR__ . '/Fixtures/Pluggable/Greeting.php';

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Core\Tests\Fixtures\Pluggable\GreetingMemoryDriver;
use Preflow\Core\Tests\Fixtures\Pluggable\GreetingProvider;
use Preflow\Core\Tests\Fixtures\Pluggable\MemoryDriver;
use Preflow\Core\Tests\Fixtures\Pluggable\MemoryNote;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;

/** config/data.php: a driver entry may name its driver by class; an unknown driver stops the boot. */
final class StorageDriverConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/storage_cfg_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'a-real-secret-key'];\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function boot(string $drivers, string $default = 'sqlite'): DataManager
    {
        file_put_contents(
            $this->dir . '/config/data.php',
            "<?php\nreturn ['default' => '{$default}', 'drivers' => ['sqlite' => ['path' => '{$this->dir}/app.sqlite'], {$drivers}]];\n",
        );
        $app = Application::create($this->dir);
        $app->boot();

        return $app->container()->get(DataManager::class);
    }

    public function test_a_class_named_driver_gets_its_options_and_the_containers_services(): void
    {
        $dm = $this->boot("'memory' => ['driver' => " . MemoryDriver::class . "::class, 'label' => 'notes']");

        $driver = $dm->driver('memory');
        $this->assertInstanceOf(MemoryDriver::class, $driver);
        $this->assertSame('notes', $driver->label);
        $this->assertInstanceOf(NonPublicPaths::class, $driver->paths);
    }

    public function test_a_class_named_driver_serves_a_type(): void
    {
        $dm = $this->boot("'memory' => ['driver' => " . MemoryDriver::class . '::class]');

        $note = new MemoryNote();
        $note->uuid = 'n1';
        $note->title = 'Buy milk';
        $dm->save($note);

        $this->assertSame('Buy milk', $dm->find(MemoryNote::class, 'n1')?->title);
        $this->assertTrue($dm->driver('memory')->exists('note', 'n1'));
    }

    public function test_a_class_named_driver_may_need_a_service_a_provider_binds(): void
    {
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(GreetingProvider::class, true) . "];\n");
        $dm = $this->boot("'memory' => ['driver' => " . GreetingMemoryDriver::class . "::class, 'label' => 'greeted']");

        $driver = $dm->driver('memory');
        $this->assertInstanceOf(GreetingMemoryDriver::class, $driver);
        $this->assertSame('hello from a provider', $driver->greeting->text());
        $this->assertSame('greeted', $driver->label);
        $this->assertSame($driver, $dm->driver('memory'), 'built once');
    }

    public function test_a_class_named_driver_can_be_the_default(): void
    {
        $dm = $this->boot("'memory' => ['driver' => " . MemoryDriver::class . '::class]', 'memory');

        $this->assertInstanceOf(MemoryDriver::class, $dm->driver('default'));
    }

    public function test_a_built_in_driver_class_under_another_name(): void
    {
        $dm = $this->boot(
            "'archive' => ['driver' => " . SqliteDriver::class . "::class, 'path' => '{$this->dir}/archive.sqlite'],"
            . "'files' => ['driver' => 'json', 'path' => '{$this->dir}/files']",
        );

        $this->assertInstanceOf(SqliteDriver::class, $dm->driver('archive'));
        $this->assertNotSame($dm->driver('sqlite'), $dm->driver('archive'));
        $dm->driver('archive')->rawQuery('SELECT 1');
        $this->assertFileExists($this->dir . '/archive.sqlite');
        $this->assertInstanceOf(JsonFileDriver::class, $dm->driver('files'));
    }

    public function test_a_built_in_name_with_its_class_keeps_working(): void
    {
        file_put_contents(
            $this->dir . '/config/data.php',
            "<?php\nreturn ['default' => 'json', 'drivers' => ["
            . "'json' => ['driver' => " . JsonFileDriver::class . "::class, 'path' => '{$this->dir}/json'],"
            . "'sqlite' => ['driver' => " . SqliteDriver::class . "::class, 'path' => '{$this->dir}/app.sqlite']]];\n",
        );
        $app = Application::create($this->dir);
        $app->boot();
        $dm = $app->container()->get(DataManager::class);

        $this->assertInstanceOf(JsonFileDriver::class, $dm->driver('default'));
        $this->assertInstanceOf(SqliteDriver::class, $dm->driver('sqlite'));
    }

    public function test_an_unknown_driver_name_throws_with_the_accepted_forms(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Unknown storage driver "pg" for drivers.pg in config/data.php. Use sqlite, mysql or json'
            . ' (as the entry\'s name or its \'driver\'), or \'driver\' => the class of a Preflow\Data\StorageDriver.',
        );

        $this->boot("'pg' => ['host' => 'localhost']");
    }

    public function test_an_unknown_driver_class_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown storage driver "App\Missing\PgDriver" for drivers.pg in config/data.php.');

        $this->boot("'pg' => ['driver' => 'App\\Missing\\PgDriver']");
    }

    public function test_a_class_that_is_no_storage_driver_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage driver stdClass for drivers.pg in config/data.php does not implement Preflow\Data\StorageDriver.');

        $this->boot("'pg' => ['driver' => \\stdClass::class]");
    }

    public function test_an_option_the_driver_does_not_take_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Storage driver ' . MemoryDriver::class . ' for drivers.memory in config/data.php has no constructor parameter "lable" (it takes: paths, label).',
        );

        $this->boot("'memory' => ['driver' => " . MemoryDriver::class . "::class, 'lable' => 'x']");
    }
}
