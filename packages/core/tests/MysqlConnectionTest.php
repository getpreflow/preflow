<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Testing\MysqlTestDatabase;

#[Group('mysql')]
final class MysqlConnectionTest extends TestCase
{
    private string $dir = '';
    private ?\PDO $cleanup = null;

    protected function tearDown(): void
    {
        if ($this->cleanup !== null) {
            $this->cleanup->exec('DROP TABLE IF EXISTS `conn_test`');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if ($this->dir !== '') {
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
    }

    /** @return list<string> */
    private function flags(\PDO $pdo): array
    {
        $flags = array_filter(explode(',', (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()));
        sort($flags);

        return $flags;
    }

    public function test_application_connection_uses_strict_mode_and_found_rows(): void
    {
        $config = MysqlTestDatabase::dataConfig();

        $this->dir = sys_get_temp_dir() . '/mysqlconn_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'a-real-secret-key'];\n");
        file_put_contents(
            $this->dir . '/config/data.php',
            "<?php\nreturn " . var_export(['default' => 'mysql', 'drivers' => ['mysql' => $config]], true) . ";\n",
        );
        $app = Application::create($this->dir);
        $app->boot();

        $dm = $app->container()->get(DataManager::class);
        $pdo = $dm->driver('mysql')->pdo();
        $this->cleanup = $pdo;

        foreach (explode(',', MysqlDriver::SQL_MODE) as $flag) {
            $this->assertContains($flag, $this->flags($pdo));
        }
        $this->assertNotContains('ANSI_QUOTES', $this->flags($pdo));

        $pdo->exec('DROP TABLE IF EXISTS `conn_test`');
        $pdo->exec('CREATE TABLE `conn_test` (id INT PRIMARY KEY, name VARCHAR(20))');
        $pdo->exec("INSERT INTO `conn_test` VALUES (1, 'a')");

        $this->assertSame(1, $dm->execute("UPDATE `conn_test` SET name = 'a' WHERE id = 1", [], 'mysql'));
    }

    public function test_session_mode_overrides_ansi_quotes(): void
    {
        $pdo = MysqlTestDatabase::reconnect();
        $this->cleanup = $pdo;
        $pdo->exec("SET SESSION sql_mode='ANSI_QUOTES'");

        $driver = new MysqlDriver($pdo);

        $this->assertNotContains('ANSI_QUOTES', $this->flags($pdo));

        $pdo->exec('DROP TABLE IF EXISTS `conn_test`');
        $pdo->exec('CREATE TABLE `conn_test` (id INT PRIMARY KEY, name VARCHAR(20))');
        $driver->execute('INSERT INTO `conn_test` (id, name) VALUES (?, ?)', [1, 'x']);

        $this->assertSame([['name' => 'x']], $driver->rawQuery('SELECT `name` FROM `conn_test`'));
    }
}
