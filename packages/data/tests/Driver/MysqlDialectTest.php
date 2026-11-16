<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDialect;
use Preflow\Data\Driver\PatternMatching;
use Preflow\Data\Driver\TextComparison;

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

    public function test_like_sql_names_the_collation(): void
    {
        $dialect = new MysqlDialect('utf8mb4_0900_as_ci');

        $this->assertInstanceOf(PatternMatching::class, $dialect);
        $this->assertSame('(CAST(`title` AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_as_ci) LIKE ?', $dialect->likeSql('`title`', '%über%'));
        $this->assertSame('(CAST(`title` AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_as_ci) NOT LIKE ?', $dialect->likeSql('`title`', '%über%', true));
        $this->assertSame("(CAST(`title` AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_as_ci) LIKE ? ESCAPE '!'", $dialect->likeSql('`title`', '%a!%%', false, '!'));
        $this->assertSame("(CAST(`title` AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_as_ci) NOT LIKE ? ESCAPE ''''", $dialect->likeSql('`title`', 'x', true, "'"));
    }

    public function test_like_sql_without_a_collation_lowers_both_sides(): void
    {
        $this->assertSame(
            'LOWER(CAST(`title` AS CHAR CHARACTER SET utf8mb4)) COLLATE utf8mb4_bin LIKE LOWER(CONVERT(? USING utf8mb4)) COLLATE utf8mb4_bin',
            $this->dialect->likeSql('`title`', '%über%'),
        );
        $this->assertSame(
            "LOWER(CAST(`title` AS CHAR CHARACTER SET utf8mb4)) COLLATE utf8mb4_bin NOT LIKE LOWER(CONVERT(? USING utf8mb4)) COLLATE utf8mb4_bin ESCAPE '!'",
            $this->dialect->likeSql('`title`', '%x%', true, '!'),
        );
    }

    /** A closure names the collation on the first LIKE, once. */
    public function test_a_closure_resolves_the_collation_once_on_first_use(): void
    {
        $calls = 0;
        $dialect = new MysqlDialect(function () use (&$calls): ?string {
            $calls++;

            return 'utf8mb4_uca1400_as_ci';
        });
        $this->assertSame(0, $calls);

        $dialect->likeSql('`a`', 'x');
        $sql = $dialect->likeSql('`b`', 'y');

        $this->assertSame(1, $calls);
        $this->assertSame('(CAST(`b` AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_uca1400_as_ci) LIKE ?', $sql);
        $this->assertStringStartsWith('LOWER(', (new MysqlDialect(static fn (): ?string => null))->likeSql('`a`', 'x'), 'a closure answering null: the fallback');
    }

    public function test_a_collation_name_is_letters_digits_and_underscores(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MysqlDialect('utf8mb4_bin) OR (1');
    }

    /** The rule's collations, NO PAD first: utf8mb4_uca1400_as_ci (PAD SPACE) would take 'abc' for 'abc '. */
    public function test_the_match_collations_are_no_pad(): void
    {
        $this->assertSame(['utf8mb4_uca1400_nopad_as_ci', 'utf8mb4_0900_as_ci'], MysqlDialect::MATCH_COLLATIONS);
    }

    public function test_text_value_names_the_collation(): void
    {
        $dialect = new MysqlDialect('utf8mb4_uca1400_nopad_as_ci');

        $this->assertInstanceOf(TextComparison::class, $dialect);
        $this->assertSame('(CAST(`s` AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_uca1400_nopad_as_ci)', $dialect->textValue('`s`'));
        $this->assertSame($this->dialect->exactText('`s`'), $this->dialect->textValue('`s`'), 'without the collation: exact, as the columns are');
        $this->assertSame('WEIGHT_STRING(`s`)', $dialect->textOrder('`s`'), 'MariaDB sorts a TEXT value by its first-level weights only');
    }
}
