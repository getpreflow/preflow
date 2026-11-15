<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Activity;

require_once __DIR__ . '/ActivityLogScenarios.php';

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Testing\MysqlTestDatabase;

/** The activity log on MySQL/MariaDB (composer test:mysql). */
#[Group('mysql')]
final class StoredActivityLogMysqlTest extends TestCase
{
    use ActivityLogScenarios;

    private ?\PDO $pdo = null;

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'mysql' => ['mysql'];
    }

    protected function dataManager(string $storage): DataManager
    {
        $this->pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($this->pdo);

        return new DataManager(['mysql' => new MysqlDriver($this->pdo)], 'mysql');
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            MysqlTestDatabase::dropAllTables($this->pdo);
        }
    }
}
