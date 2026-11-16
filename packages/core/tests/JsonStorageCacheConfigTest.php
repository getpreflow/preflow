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

    /**
     * A file edited in place outside the app (a git pull): same size and modification time, so the
     * cache's signature cannot see it. With the cache on the old text stays (the README's edge
     * case); with 'cache' => false the new text is read at once.
     */
    public function test_a_file_changed_outside_the_app_is_read_at_once_without_cache(): void
    {
        $stale = $this->manager('')->driver('json');
        $stale->save('notes', 'a', ['uuid' => 'a', 'title' => 'one']);
        $this->assertSame('one', $stale->findMany('notes', new Query())->items()[0]['title']);
        $this->editInPlace();
        $this->assertSame('one', $stale->findMany('notes', new Query())->items()[0]['title'], 'the cache does not see the edit');

        $fresh = $this->manager(", 'cache' => false")->driver('json');
        $this->assertSame('two', $fresh->findMany('notes', new Query())->items()[0]['title']);
        $this->editInPlace('two', 'six');
        $this->assertSame('six', $fresh->findMany('notes', new Query())->items()[0]['title'], 'without the cache it reads the edit at once');
    }

    /** Replaces $from with $to (same length) in the record file, keeping its size and modification time. */
    private function editInPlace(string $from = 'one', string $to = 'two'): void
    {
        $file = glob($this->dir . '/json/notes/*.json')[0];
        clearstatcache(true, $file);
        $mtime = (int) filemtime($file);
        $bytes = (string) file_get_contents($file);
        $edited = str_replace('"' . $from . '"', '"' . $to . '"', $bytes);
        $this->assertNotSame($bytes, $edited);
        $this->assertSame(strlen($bytes), strlen($edited));
        file_put_contents($file, $edited);
        touch($file, $mtime);
        clearstatcache(true, $file);
    }
}
