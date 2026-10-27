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

    public function test_update_targets_one_row_by_id(): void
    {
        $this->assertSame(
            'UPDATE "posts" SET "title" = ?, "body" = ? WHERE "uuid" = ?',
            $this->dialect->updateSql('posts', ['title', 'body'], 'uuid'),
        );
    }

    public function test_upsert_conflicts_on_the_id_only(): void
    {
        $this->assertSame(
            'INSERT INTO "posts" ("uuid", "title") VALUES (?, ?) ON CONFLICT("uuid") DO UPDATE SET "title" = excluded."title"',
            $this->dialect->upsertSql('posts', ['uuid', 'title'], 'uuid'),
        );
        $this->assertStringEndsWith('DO NOTHING', $this->dialect->upsertSql('posts', ['uuid'], 'uuid'));
    }
}
