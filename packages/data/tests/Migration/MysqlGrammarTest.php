<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\Grammar\MysqlGrammar;
use Preflow\Data\Migration\Grammar\SqliteGrammar;

/** Pure SQL strings: no database needed. */
final class MysqlGrammarTest extends TestCase
{
    public function test_create_table(): void
    {
        $sql = (new MysqlGrammar())->createTable('t', [
            ['name' => 'id', 'kind' => 'uuid', 'length' => null, 'nullable' => false, 'primary' => true],
            ['name' => 'name', 'kind' => 'string', 'length' => null, 'nullable' => true, 'primary' => false],
            ['name' => 'code', 'kind' => 'string', 'length' => 32, 'nullable' => false, 'primary' => false],
            ['name' => 'n', 'kind' => 'integer', 'length' => null, 'nullable' => false, 'primary' => false],
            ['name' => 'b', 'kind' => 'boolean', 'length' => null, 'nullable' => false, 'primary' => false],
            ['name' => 'j', 'kind' => 'json', 'length' => null, 'nullable' => false, 'primary' => false],
            ['name' => 'x', 'kind' => 'text', 'length' => null, 'nullable' => false, 'primary' => false],
        ]);

        $this->assertSame(
            'CREATE TABLE `t` (`id` VARCHAR(64) COLLATE utf8mb4_bin PRIMARY KEY, `name` VARCHAR(255), `code` VARCHAR(32) NOT NULL, '
            . '`n` BIGINT NOT NULL, `b` TINYINT(1) NOT NULL, `j` LONGTEXT NOT NULL, `x` TEXT NOT NULL) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $sql,
        );
    }

    /** Ids compare exactly (as on SQLite); integer keys take no collation, value columns keep the table's. */
    public function test_keys_are_binary_values_are_not(): void
    {
        $g = new MysqlGrammar();

        $this->assertSame(
            'CREATE TABLE `s` (`slug` VARCHAR(80) COLLATE utf8mb4_bin PRIMARY KEY, `ref` VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, `name` VARCHAR(255)) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $g->createTable('s', [
                ['name' => 'slug', 'kind' => 'string', 'length' => 80, 'nullable' => false, 'primary' => true],
                ['name' => 'ref', 'kind' => 'uuid', 'length' => null, 'nullable' => false, 'primary' => false],
                ['name' => 'name', 'kind' => 'string', 'length' => null, 'nullable' => true, 'primary' => false],
            ]),
        );
        $this->assertSame(
            'CREATE TABLE `n` (`id` BIGINT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $g->createTable('n', [['name' => 'id', 'kind' => 'integer', 'length' => null, 'nullable' => false, 'primary' => true]]),
        );
    }

    public function test_create_index_drop_and_add_column(): void
    {
        $g = new MysqlGrammar();

        $this->assertSame('CREATE INDEX `idx_t_name` ON `t` (`name`)', $g->createIndex('t', 'name'));
        $this->assertSame('DROP TABLE IF EXISTS `t`', $g->dropTable('t'));
        $this->assertSame('ALTER TABLE `t` ADD COLUMN `c` LONGTEXT', $g->addColumn('t', 'c'));
        $this->assertSame('ALTER TABLE `t` ADD COLUMN `c` BIGINT', $g->addColumn('t', 'c', 'BIGINT'));
    }

    public function test_migrations_table(): void
    {
        $this->assertSame(
            'CREATE TABLE IF NOT EXISTS `preflow_migrations` (`migration` VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, `ran_at` VARCHAR(32) NOT NULL) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            (new MysqlGrammar())->createMigrationsTable(),
        );
    }

    public function test_only_bounded_kinds_can_be_indexed(): void
    {
        $g = new MysqlGrammar();

        $this->assertTrue($g->canIndex('string'));
        $this->assertTrue($g->canIndex('uuid'));
        $this->assertTrue($g->canIndex('integer'));
        $this->assertFalse($g->canIndex('text'));
        $this->assertFalse($g->canIndex('json'));
    }

    public function test_record_table_mysql(): void
    {
        $this->assertSame(
            'CREATE TABLE `posts` (`id` VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, `title` LONGTEXT, `body` LONGTEXT) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            (new MysqlGrammar())->createRecordTable('posts', 'id', ['title', 'body']),
        );
    }

    public function test_record_table_sqlite_matches_what_folio_builds_today(): void
    {
        $this->assertSame(
            'CREATE TABLE "posts" ("id" TEXT PRIMARY KEY, "title" TEXT, "body" TEXT)',
            (new SqliteGrammar())->createRecordTable('posts', 'id', ['title', 'body']),
        );
    }
}
