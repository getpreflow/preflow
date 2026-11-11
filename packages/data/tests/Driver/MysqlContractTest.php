<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

require_once __DIR__ . '/ContractTables.php';

use PHPUnit\Framework\Attributes\Group;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\StorageDriver;
use Preflow\Testing\Contract\StorageDriverContract;
use Preflow\Testing\MysqlTestDatabase;

/** The storage driver contract on MySQL/MariaDB (`composer test:mysql`; skipped without MYSQL_* settings). */
#[Group('mysql')]
final class MysqlContractTest extends StorageDriverContract
{
    use ContractTables;

    private MysqlDriver $mysql;

    protected function createDriver(): StorageDriver
    {
        $pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($pdo);

        return $this->mysql = new MysqlDriver($pdo);
    }

    protected function createType(string $type, array $fields): void
    {
        self::createContractTable($this->mysql, $type, $fields, 'BIGINT AUTO_INCREMENT PRIMARY KEY');
    }

    protected function tearDown(): void
    {
        if (isset($this->mysql)) {
            MysqlTestDatabase::dropAllTables($this->mysql->pdo());
        }
        parent::tearDown();
    }
}
