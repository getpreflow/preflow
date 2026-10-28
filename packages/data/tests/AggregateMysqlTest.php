<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

require_once __DIR__ . '/AggregateScenarios.php';

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Testing\MysqlTestDatabase;

/** The aggregate scenarios on MySQL/MariaDB. */
#[Group('mysql')]
final class AggregateMysqlTest extends TestCase
{
    use AggregateScenarios;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($this->pdo);
        $this->dm = new DataManager(['mysql' => new MysqlDriver($this->pdo)], 'mysql');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            MysqlTestDatabase::dropAllTables($this->pdo);
        }
    }

    protected function storage(): string
    {
        return 'mysql';
    }
}
