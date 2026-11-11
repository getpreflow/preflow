<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use Preflow\Data\Driver\Dialect;
use Preflow\Data\Driver\SqliteDialect;
use Preflow\Testing\Contract\DialectContract;

/** The dialect contract on SQLite (an in-memory database per test). */
final class SqliteDialectContractTest extends DialectContract
{
    protected function createPdo(): \PDO
    {
        return new \PDO('sqlite::memory:');
    }

    protected function createDialect(): Dialect
    {
        return new SqliteDialect();
    }

    /** Tables made by preflow migrate give a number field NUMERIC affinity. */
    protected function numberColumnType(): string
    {
        return 'NUMERIC';
    }
}
