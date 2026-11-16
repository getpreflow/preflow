<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

require_once __DIR__ . '/ContractTables.php';

use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\StorageDriver;
use Preflow\Testing\Contract\StorageDriverContract;

/**
 * The storage driver contract on SQLite, in a database file of its own per test (as sites run
 * it), so the scenarios that write from several processes at once (reopenDriver()) run too.
 */
final class SqliteContractTest extends StorageDriverContract
{
    use ContractTables;

    private SqliteDriver $sqlite;

    private string $file;

    protected function createDriver(): StorageDriver
    {
        $this->file = sys_get_temp_dir() . '/preflow_sqlite_contract_' . bin2hex(random_bytes(6)) . '.sqlite';

        return $this->sqlite = new SqliteDriver(new \PDO('sqlite:' . $this->file));
    }

    protected function reopenDriver(): ?StorageDriver
    {
        return new SqliteDriver(new \PDO('sqlite:' . $this->file));
    }

    protected function emptyDriver(): ?StorageDriver
    {
        return new SqliteDriver(new \PDO('sqlite:' . $this->file . '.restored'));
    }

    protected function createType(string $type, array $fields): void
    {
        self::createContractTable($this->sqlite, $type, $fields, 'INTEGER PRIMARY KEY AUTOINCREMENT');
    }

    protected function declareUnique(string $type, string $field): void
    {
        self::createContractUnique($this->sqlite, $type, $field);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->file . '*') ?: [] as $file) {
            unlink($file);
        }
        parent::tearDown();
    }
}
