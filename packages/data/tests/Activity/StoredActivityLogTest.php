<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Activity;

require_once __DIR__ . '/ActivityLogScenarios.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;

/** The activity log on JSON files and SQLite (MySQL: StoredActivityLogMysqlTest). */
final class StoredActivityLogTest extends TestCase
{
    use ActivityLogScenarios;

    private string $dir = '';

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'json' => ['json'];
        yield 'sqlite' => ['sqlite'];
    }

    protected function dataManager(string $storage): DataManager
    {
        $this->dir = sys_get_temp_dir() . '/activity_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new DataManager(['sqlite' => new SqliteDriver($pdo), 'json' => new JsonFileDriver($this->dir . '/store')], $storage);
    }

    protected function tearDown(): void
    {
        if ($this->dir === '') {
            return;
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
}
