<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Transfer;

use PHPUnit\Framework\Attributes\Group;
use Preflow\Testing\MysqlTestDatabase;

#[Group('mysql')]
final class ImportReplacesMysqlTest extends ImportReplacesSqliteTest
{
    protected function setUp(): void
    {
        MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        parent::setUp();
    }

    protected function storage(): string
    {
        return 'mysql';
    }
}
