<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDialect;
use Preflow\Data\Driver\PatternMatching;

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

    /**
     * The lookup reads the server's collation list, not its version. MariaDB 10.10+ lists its UCA
     * 14 collations without a character set (uca1400_as_ci, applicable to utf8mb4); 11.4.5+ adds
     * utf8mb4_0900_as_ci as an alias; MySQL 8 lists utf8mb4_0900_as_ci; MariaDB before 10.10 none.
     */
    public function test_the_collation_lookup_reads_what_the_server_lists(): void
    {
        $server = static function (array $applicable): \PDO {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
            $pdo->exec('CREATE TABLE information_schema.COLLATION_CHARACTER_SET_APPLICABILITY (COLLATION_NAME TEXT, CHARACTER_SET_NAME TEXT)');
            $insert = $pdo->prepare('INSERT INTO information_schema.COLLATION_CHARACTER_SET_APPLICABILITY VALUES (?, ?)');
            foreach ($applicable as [$collation, $charset]) {
                $insert->execute([$collation, $charset]);
            }

            return $pdo;
        };

        $mariadb1010 = [['uca1400_as_ci', 'utf8mb4'], ['uca1400_as_ci', 'utf8mb3'], ['utf8mb4_unicode_ci', 'utf8mb4']];
        $mariadb1145 = [['utf8mb4_0900_as_ci', 'utf8mb4'], ['uca1400_as_ci', 'utf8mb4'], ['uca1400_as_ci', 'ucs2']];
        $mysql8 = [['utf8mb4_0900_as_ci', 'utf8mb4'], ['utf8mb4_0900_ai_ci', 'utf8mb4']];
        $mariadb106 = [['utf8mb4_unicode_ci', 'utf8mb4'], ['utf8mb4_unicode_520_ci', 'utf8mb4']];
        $otherCharset = [['uca1400_as_ci', 'utf8mb3']];

        $this->assertSame('utf8mb4_uca1400_as_ci', MysqlDialect::serverMatchCollation($server($mariadb1010)));
        $this->assertSame('utf8mb4_uca1400_as_ci', MysqlDialect::serverMatchCollation($server($mariadb1145)), 'preference order');
        $this->assertSame('utf8mb4_0900_as_ci', MysqlDialect::serverMatchCollation($server($mysql8)));
        $this->assertNull(MysqlDialect::serverMatchCollation($server($mariadb106)));
        $this->assertNull(MysqlDialect::serverMatchCollation($server($otherCharset)), 'only for utf8mb4');
    }
}
