<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;

final class LazyDatabaseTest extends TestCase
{
    public function test_boot_opens_no_database_connection_until_a_query_runs(): void
    {
        $dir = sys_get_temp_dir() . '/lazydb_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'a-real-secret-key'];\n");
        file_put_contents($dir . '/config/data.php', "<?php\nreturn " . var_export(['default' => 'sqlite', 'drivers' => ['sqlite' => ['path' => $dir . '/db/app.sqlite']]], true) . ";\n");

        try {
            $app = Application::create($dir);
            $app->boot();
            $driver = $app->container()->get(DataManager::class)->driver('sqlite');

            $this->assertFalse($driver->connected());
            $this->assertFileDoesNotExist($dir . '/db/app.sqlite');

            $pdo = $app->container()->get(\PDO::class);
            $this->assertTrue($driver->connected());
            $this->assertSame($pdo, $driver->pdo());
            $this->assertSame($pdo, $app->container()->get(\PDO::class));
        } finally {
            foreach (glob($dir . '/db/*') ?: [] as $f) {
                unlink($f);
            }
            @rmdir($dir . '/db');
            @unlink($dir . '/config/app.php');
            @unlink($dir . '/config/data.php');
            @rmdir($dir . '/config');
            @rmdir($dir);
        }
    }
}
