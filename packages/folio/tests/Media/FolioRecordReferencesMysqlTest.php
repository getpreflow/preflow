<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Media;

use PHPUnit\Framework\Attributes\Group;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Testing\MysqlTestDatabase;

/** The prefiltered usage scan on MySQL (LIKE on TEXT/LONGTEXT columns). */
#[Group('mysql')]
final class FolioRecordReferencesMysqlTest extends FolioRecordReferencesSqlTest
{
    protected function setUp(): void
    {
        MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (MysqlTestDatabase::configured()) {
            MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        }
    }

    protected function driver(): PdoDriver
    {
        return new MysqlDriver(MysqlTestDatabase::pdo(), $this->log);
    }

    protected function driverOn(DebugCollector $collector): PdoDriver
    {
        return new MysqlDriver(MysqlTestDatabase::pdo(), $collector);
    }
}
