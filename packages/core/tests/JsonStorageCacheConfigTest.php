<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\Query;

final class JsonStorageCacheConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jsoncache_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'a-real-secret-key'];\n");
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $rm = function (string $p) use (&$rm): void {
            foreach (glob($p . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (in_array(basename($f), ['.', '..'], true)) {
                    continue;
                }
                is_dir($f) ? $rm($f) : unlink($f);
            }
            @rmdir($p);
        };
        $rm($this->dir);
    }

    private function manager(string $entry): DataManager
    {
        file_put_contents(
            $this->dir . '/config/data.php',
            "<?php\nreturn ['default' => 'json', 'drivers' => ['json' => ['path' => '{$this->dir}/json'{$entry}]]];\n",
        );
        $app = Application::create($this->dir);
        $app->boot();

        return $app->container()->get(DataManager::class);
    }

    public function test_a_json_storage_with_cache_false_reads_without_a_cache(): void
    {
        $this->assertNull($this->manager(", 'cache' => false")->driver('json')->cacheDir());
    }

    public function test_the_json_cache_stays_on_by_default(): void
    {
        $this->assertNotNull($this->manager('')->driver('json')->cacheDir());
    }

    public function test_a_file_changed_outside_the_app_is_read_at_once_without_cache(): void
    {
        $driver = $this->manager(", 'cache' => false")->driver('json');
        $driver->save('notes', 'a', ['uuid' => 'a', 'title' => 'one']);

        $this->assertSame('one', $driver->findMany('notes', new Query())->items()[0]['title']);

        $file = glob($this->dir . '/json/notes/*.json')[0];
        file_put_contents($file, json_encode(['uuid' => 'a', 'title' => 'two']));

        $this->assertSame('two', $driver->findMany('notes', new Query())->items()[0]['title']);
    }
}
