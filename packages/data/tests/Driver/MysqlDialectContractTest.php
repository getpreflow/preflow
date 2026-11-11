<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\Attributes\Group;
use Preflow\Data\Driver\Dialect;
use Preflow\Data\Driver\MysqlDialect;
use Preflow\Testing\Contract\DialectContract;
use Preflow\Testing\MysqlTestDatabase;

/** The dialect contract on MySQL/MariaDB in strict mode (`composer test:mysql`; skipped without MYSQL_* settings). */
#[Group('mysql')]
final class MysqlDialectContractTest extends DialectContract
{
    protected function createPdo(): \PDO
    {
        $pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($pdo);

        return $pdo;
    }

    protected function createDialect(): Dialect
    {
        return new MysqlDialect();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            MysqlTestDatabase::dropAllTables($this->pdo);
        }
    }
}
