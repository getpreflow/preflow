<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\SqliteDialect;

final class SqliteDialectTest extends TestCase
{
    private SqliteDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new SqliteDialect();
    }

    public function test_quotes_identifier_with_double_quotes(): void
    {
        $this->assertSame('"users"', $this->dialect->quoteIdentifier('users'));
    }

    public function test_upsert_generates_insert_or_replace(): void
    {
        $sql = $this->dialect->upsertSql('posts', ['uuid', 'title', 'body'], 'uuid');

        $this->assertStringContainsString('INSERT OR REPLACE INTO', $sql);
        $this->assertStringContainsString('"posts"', $sql);
        $this->assertStringContainsString('"uuid"', $sql);
        $this->assertStringContainsString('"title"', $sql);
        $this->assertSame(3, substr_count($sql, '?'));
    }
}
