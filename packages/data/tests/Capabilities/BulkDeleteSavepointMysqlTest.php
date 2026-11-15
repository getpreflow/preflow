<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Capabilities;

require_once __DIR__ . '/BulkDeleteSavepointScenarios.php';

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Testing\MysqlTestDatabase;

#[Group('mysql')]
final class BulkDeleteSavepointMysqlTest extends TestCase
{
    use BulkDeleteSavepointScenarios;

    private ?\PDO $pdo = null;

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'mysql' => ['mysql'];
    }

    protected function storagesWithTransactions(): array
    {
        return ['mysql'];
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
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            MysqlTestDatabase::dropAllTables($this->pdo);
        }
    }
}
