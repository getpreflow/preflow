<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDialect;

final class MysqlDialectTest extends TestCase
{
    private MysqlDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new MysqlDialect();
    }

    public function test_quotes_identifier_with_backticks(): void
    {
        $this->assertSame('`users`', $this->dialect->quoteIdentifier('users'));
    }

    public function test_upsert_generates_on_duplicate_key_update(): void
    {
        $sql = $this->dialect->upsertSql('posts', ['uuid', 'title', 'body'], 'uuid');

        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringContainsString('`posts`', $sql);
        $this->assertStringContainsString('`title` = VALUES(`title`)', $sql);
        $this->assertStringContainsString('`body` = VALUES(`body`)', $sql);
        $this->assertStringNotContainsString('`uuid` = VALUES(`uuid`)', $sql);
        $this->assertSame(3, substr_count($sql, '?'));
    }
}
