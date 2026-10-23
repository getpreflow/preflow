<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Testing\MysqlTestDatabase;

#[Group('mysql')]
final class MysqlTestDatabaseTest extends TestCase
{
    public function test_pdo_connects_with_strict_session_mode(): void
    {
        $mode = (string) MysqlTestDatabase::pdo()->query('SELECT @@SESSION.sql_mode')->fetchColumn();

        $this->assertStringContainsString('STRICT_TRANS_TABLES', $mode);
        $this->assertStringNotContainsString('ANSI_QUOTES', $mode);
    }

    public function test_drop_all_tables_empties_the_database(): void
    {
        $pdo = MysqlTestDatabase::pdo();
        $pdo->exec('CREATE TABLE t_a (id INT PRIMARY KEY)');
        $pdo->exec('CREATE TABLE t_b (id INT PRIMARY KEY)');

        MysqlTestDatabase::dropAllTables($pdo);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function test_found_rows_counts_matched_rows(): void
    {
        $pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($pdo);
        $pdo->exec('CREATE TABLE t_f (id INT PRIMARY KEY, v INT)');
        $pdo->exec('INSERT INTO t_f VALUES (1, 5)');

        $stmt = $pdo->prepare('UPDATE t_f SET v = 5 WHERE id = 1');
        $stmt->execute();

        $this->assertSame(1, $stmt->rowCount());
        MysqlTestDatabase::dropAllTables($pdo);
    }
}
