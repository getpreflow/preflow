<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;
use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;

final class DataAliasesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dataalias_' . bin2hex(random_bytes(4));
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

    private function boot(string $dataConfig): Application
    {
        file_put_contents($this->dir . '/config/data.php', "<?php\nreturn " . $dataConfig . ";\n");
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    private function config(string $default, string $aliases): string
    {
        return "['default' => '{$default}', 'drivers' => ["
            . "'sqlite' => ['path' => '{$this->dir}/app.sqlite'],"
            . "'json' => ['path' => '{$this->dir}/json'],"
            . "], 'aliases' => {$aliases}]";
    }

    public function test_alias_resolves_to_same_driver(): void
    {
        $app = $this->boot($this->config('json', "['codes' => 'sqlite', 'throttle' => 'sqlite']"));
        $dm = $app->container()->get(DataManager::class);

        $this->assertSame($dm->driver('sqlite'), $dm->driver('codes'));
        $this->assertSame($dm->driver('sqlite'), $dm->driver('throttle'));
        $this->assertSame($dm->driver('json'), $dm->driver('default'));
    }

    public function test_alias_to_default_is_allowed(): void
    {
        $app = $this->boot($this->config('json', "['codes' => 'default']"));
        $dm = $app->container()->get(DataManager::class);

        $this->assertSame($dm->driver('default'), $dm->driver('codes'));
    }

    public function test_alias_named_default_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage alias [default] collides with a configured driver.');
        $this->boot($this->config('json', "['default' => 'sqlite']"));
    }

    public function test_alias_to_unknown_driver_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage alias [codes] points to [mysql], which is not a configured driver.');
        $this->boot($this->config('json', "['codes' => 'mysql']"));
    }

    public function test_alias_colliding_with_driver_key_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage alias [json] collides with a configured driver.');
        $this->boot($this->config('json', "['json' => 'sqlite']"));
    }

    public function test_throttle_uses_throttle_alias_when_configured(): void
    {
        $app = $this->boot($this->config('json', "['throttle' => 'sqlite']"));
        $this->migrate($app);

        $app->container()->get(Throttle::class)->hit('code', 'c1', 60);

        $rows = $app->container()->get(\PDO::class)->query('SELECT purpose, hits FROM preflow_throttle')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame([['purpose' => 'code', 'hits' => 1]], $rows);
    }

    public function test_throttle_defaults_to_default_storage(): void
    {
        $app = $this->boot($this->config('sqlite', '[]'));
        $this->migrate($app);

        $app->container()->get(Throttle::class)->hit('code', 'c1', 60);

        $rows = $app->container()->get(\PDO::class)->query('SELECT purpose, hits FROM preflow_throttle')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame([['purpose' => 'code', 'hits' => 1]], $rows);
    }

    private function migrate(Application $app): void
    {
        /** @var Migration $migration */
        $migration = require dirname(__DIR__) . '/migrations/2026_10_22_000000_preflow_throttle.php';
        $migration->up(new Schema($app->container()->get(\PDO::class)));
    }
}
