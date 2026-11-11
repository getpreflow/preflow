<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

require_once __DIR__ . '/ContractTables.php';

use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\StorageDriver;
use Preflow\Testing\Contract\StorageDriverContract;

/** The storage driver contract on SQLite (in memory). */
final class SqliteContractTest extends StorageDriverContract
{
    use ContractTables;

    private SqliteDriver $sqlite;

    protected function createDriver(): StorageDriver
    {
        return $this->sqlite = new SqliteDriver(new \PDO('sqlite::memory:'));
    }

    protected function createType(string $type, array $fields): void
    {
        self::createContractTable($this->sqlite, $type, $fields, 'INTEGER PRIMARY KEY AUTOINCREMENT');
    }
}
