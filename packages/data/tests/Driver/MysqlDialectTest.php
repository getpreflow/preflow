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
        $this->assertSame('`title`` OR 1=1 --`', $this->dialect->quoteIdentifier('title` OR 1=1 --'));
    }

    public function test_numeric_value_compares_empty_as_text_and_adds_a_double(): void
    {
        // NULLIF on the number itself would turn 0 into NULL ('' = 0 in MySQL); "+ 0" without
        // the double gives MAX() no decimals on MariaDB.
        $this->assertSame("(NULLIF(CAST(`n` AS CHAR), '') + 0e0)", $this->dialect->numericValue('`n`'));
    }

    public function test_exact_text_compares_case(): void
    {
        $this->assertSame('(CAST(`s` AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin)', $this->dialect->exactText('`s`'));
    }

    public function test_update_targets_one_row_by_id(): void
    {
        $this->assertSame(
            'UPDATE `posts` SET `title` = ?, `body` = ? WHERE `uuid` = ?',
            $this->dialect->updateSql('posts', ['title', 'body'], 'uuid'),
        );
    }

    public function test_no_single_statement_upsert(): void
    {
        $this->assertNull($this->dialect->upsertSql('posts', ['uuid', 'title'], 'uuid'));
    }

    public function test_duplicate_keys_are_told_apart_by_the_key_that_ends_the_message(): void
    {
        $error = static function (string $message): \PDOException {
            $e = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 ' . $message);
            $e->errorInfo = ['23000', 1062, $message];

            return $e;
        };

        $this->assertTrue($this->dialect->isPrimaryKeyViolation($error("Duplicate entry 'a' for key 'PRIMARY'"), 'posts', 'uuid'));
        $this->assertTrue($this->dialect->isPrimaryKeyViolation($error("Duplicate entry 'a' for key 'posts.PRIMARY'"), 'posts', 'uuid'));
        $sneaky = $error("Duplicate entry 'x for key 'PRIMARY'' for key 'posts.uniq_posts_slug'");
        $this->assertFalse($this->dialect->isPrimaryKeyViolation($sneaky, 'posts', 'uuid'));
        $this->assertSame('uniq_posts_slug', $this->dialect->uniqueViolation($sneaky, 'posts', 'uuid')?->index);
        $this->assertNull($this->dialect->uniqueViolation($error("Duplicate entry 'a' for key 'PRIMARY'"), 'posts', 'uuid'));
    }

    public function test_insert_or_update_uses_on_duplicate_key(): void
    {
        $this->assertSame(
            'INSERT INTO `c` (`id`, `n`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `n` = `n` + 1',
            $this->dialect->insertOrUpdateSql('c', ['id', 'n'], 'id', ['n' => '`n` + 1']),
        );
    }
}
