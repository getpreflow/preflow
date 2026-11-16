<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\Grammar\MysqlGrammar;
use Preflow\Data\Migration\Grammar\TextCollations;
use Preflow\Data\Migration\Table;
use Preflow\Data\Migration\Grammar\SqliteGrammar;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;

/** Pure SQL strings: no database needed (a fake connection answers the collation probe). */
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

    public function test_identifier_at_64_characters_passes(): void
    {
        $name = str_repeat('t', 64);

        $this->assertSame("DROP TABLE IF EXISTS `{$name}`", (new MysqlGrammar())->dropTable($name));
    }

    public function test_index_name_over_64_characters_throws(): void
    {
        $table = str_repeat('t', 40);
        $column = str_repeat('c', 20);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Identifier \"idx_{$table}_{$column}\" is 65 characters; MySQL allows at most 64.");

        (new MysqlGrammar())->createIndex($table, $column);
    }

    public function test_shadow_table_name_over_64_characters_throws(): void
    {
        $table = str_repeat('p', 54) . '__published';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Identifier \"{$table}\" is 65 characters; MySQL allows at most 64.");

        (new MysqlGrammar())->createRecordTable($table, 'uuid', [new ColumnSpec('title')]);
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
            'CREATE TABLE `posts` (`id` VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, `title` LONGTEXT COLLATE utf8mb4_bin, `body` LONGTEXT COLLATE utf8mb4_bin) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            (new MysqlGrammar())->createRecordTable('posts', 'id', [new ColumnSpec('title'), new ColumnSpec('body')]),
        );
    }

    public function test_record_table_sqlite_matches_what_folio_builds_today(): void
    {
        $this->assertSame(
            'CREATE TABLE "posts" ("id" TEXT PRIMARY KEY, "title" TEXT, "body" TEXT)',
            (new SqliteGrammar())->createRecordTable('posts', 'id', [new ColumnSpec('title'), new ColumnSpec('body')]),
        );
    }

    public function test_typed_record_table(): void
    {
        $columns = [
            new ColumnSpec('body'),
            new ColumnSpec('slug', ColumnKind::Short),
            new ColumnSpec('code', ColumnKind::Short, 40),
            new ColumnSpec('seats', ColumnKind::Number),
            new ColumnSpec('open', ColumnKind::Boolean),
            new ColumnSpec('starts', ColumnKind::Date),
        ];

        $this->assertSame(
            'CREATE TABLE `e` (`uuid` VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, `body` LONGTEXT COLLATE utf8mb4_bin, `slug` VARCHAR(191) COLLATE utf8mb4_bin, '
            . '`code` VARCHAR(40) COLLATE utf8mb4_bin, `seats` DOUBLE, `open` TINYINT(1), `starts` VARCHAR(32) COLLATE utf8mb4_bin) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            (new MysqlGrammar())->createRecordTable('e', 'uuid', $columns),
        );
        $this->assertSame(
            'CREATE TABLE "e" ("uuid" TEXT PRIMARY KEY, "body" TEXT, "slug" TEXT, "code" TEXT, "seats" NUMERIC, "open" INTEGER, "starts" TEXT)',
            (new SqliteGrammar())->createRecordTable('e', 'uuid', $columns),
        );
        $this->assertSame(
            'CREATE UNIQUE INDEX `uniq_e_code` ON `e` (`code`, `purpose`)',
            (new MysqlGrammar())->createRecordIndex('uniq_e_code', 'e', ['code', 'purpose'], true),
        );
        $this->assertSame('CREATE INDEX "idx_e_slug" ON "e" ("slug")', (new SqliteGrammar())->createRecordIndex('idx_e_slug', 'e', ['slug'], false));
    }

    /** A column made before it was indexed may be TEXT: MySQL indexes its first 191 characters, SQLite the whole value. */
    public function test_a_text_column_is_indexed_by_a_prefix_on_mysql(): void
    {
        $types = ['company' => 'longtext', 'state' => 'varchar(191)', 'body' => 'mediumblob'];

        $this->assertSame(
            'CREATE INDEX `idx_e_company_state_body` ON `e` (`company`(191), `state`, `body`(191))',
            (new MysqlGrammar())->createRecordIndex('idx_e_company_state_body', 'e', ['company', 'state', 'body'], false, $types),
        );
        $this->assertSame(
            'CREATE INDEX "idx_e_company" ON "e" ("company")',
            (new SqliteGrammar())->createRecordIndex('idx_e_company', 'e', ['company'], false, ['company' => 'text']),
        );
    }

    /** The prefix is the declared length where that is shorter, never more than 191 characters. */
    public function test_the_prefix_is_the_declared_length_at_most_191(): void
    {
        $types = ['code' => 'longtext', 'company' => 'longtext', 'state' => 'varchar(40)'];

        $this->assertSame(
            'CREATE INDEX `idx_e_code_company_state` ON `e` (`code`(40), `company`(191), `state`)',
            (new MysqlGrammar())->createRecordIndex('idx_e_code_company_state', 'e', ['code', 'company', 'state'], false, $types, ['code' => 40, 'company' => 500, 'state' => 40]),
        );
    }

    // --- the text rule's collation (TextCollations, @since 0.78) -------------------------------

    public function test_text_columns_name_the_bound_collation(): void
    {
        $g = (new MysqlGrammar())->on(new FakeMysqlPdo(['utf8mb4_0900_as_ci']));

        $this->assertInstanceOf(TextCollations::class, $g);
        $this->assertSame('utf8mb4_0900_as_ci', $g->textCollation());
        $this->assertSame('VARCHAR(80) COLLATE utf8mb4_0900_as_ci', $g->textColumnType(ColumnKind::Short, 80));
        $this->assertSame('VARCHAR(191) COLLATE utf8mb4_0900_as_ci', $g->textColumnType(ColumnKind::Short));
        $this->assertSame('LONGTEXT COLLATE utf8mb4_0900_as_ci', $g->textColumnType(ColumnKind::Text));
        $this->assertSame('VARCHAR(40) COLLATE utf8mb4_0900_as_ci', $g->textType('string', 40));
        $this->assertSame('TEXT COLLATE utf8mb4_0900_as_ci', $g->textType('text'));
        $this->assertSame('BIGINT', $g->textType('integer'), 'not a text kind: its own type');
        $this->assertNull((new MysqlGrammar())->textCollation(), 'an unbound grammar has none');
    }

    public function test_probe_prefers_the_nopad_collation(): void
    {
        $both = new FakeMysqlPdo(['utf8mb4_0900_as_ci', 'utf8mb4_uca1400_nopad_as_ci']);
        $this->assertSame('utf8mb4_uca1400_nopad_as_ci', (new MysqlGrammar())->on($both)->textCollation());
        $this->assertSame(['utf8mb4_uca1400_nopad_as_ci'], $both->probed, 'the first accepted one ends the probe');
        (new MysqlGrammar())->on($both);
        $this->assertCount(1, $both->probed, 'probed once per connection');

        $old = (new MysqlGrammar())->on(new FakeMysqlPdo([]));
        $this->assertNull($old->textCollation(), 'an old server: none');
        $this->assertSame('LONGTEXT COLLATE utf8mb4_bin', $old->textColumnType(ColumnKind::Text), 'its text columns stay exact');
        $this->assertSame((new MysqlGrammar())->recordColumnType(ColumnKind::Short, 80), $old->textColumnType(ColumnKind::Short, 80));
        $this->assertSame('VARCHAR(255)', $old->textType('string'));
    }

    public function test_a_failure_other_than_an_unknown_collation_is_not_swallowed(): void
    {
        $pdo = new FakeMysqlPdo([]);
        $pdo->failWith = 2006; // server gone away

        $this->expectException(\PDOException::class);
        (new MysqlGrammar())->on($pdo);
    }

    public function test_record_table_marks_text_and_exact_columns(): void
    {
        $g = (new MysqlGrammar())->on(new FakeMysqlPdo(['utf8mb4_0900_as_ci']));

        $this->assertSame(
            'CREATE TABLE `e` (`uuid` VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, `title` VARCHAR(80) COLLATE utf8mb4_0900_as_ci, '
            . '`body` LONGTEXT COLLATE utf8mb4_0900_as_ci, `blocks` LONGTEXT COLLATE utf8mb4_bin, `d` VARCHAR(32) COLLATE utf8mb4_bin, `n` DOUBLE) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_ci',
            $g->createRecordTable('e', 'uuid', [
                new ColumnSpec('title', ColumnKind::Short, 80, text: true),
                new ColumnSpec('body', text: true),
                new ColumnSpec('blocks'),
                new ColumnSpec('d', ColumnKind::Date),
                new ColumnSpec('n', ColumnKind::Number),
            ]),
        );
    }

    public function test_migration_string_columns_are_text_unless_exact(): void
    {
        $table = new Table('t');
        $table->uuid('id')->primary();
        $table->string('email');
        $table->string('key')->exact();
        $table->text('body')->nullable();
        $table->text('blob')->exact()->nullable();
        $table->uuid('ref');

        $this->assertSame(
            'CREATE TABLE `t` (`id` VARCHAR(64) COLLATE utf8mb4_bin PRIMARY KEY, `email` VARCHAR(255) COLLATE utf8mb4_0900_as_ci NOT NULL, '
            . '`key` VARCHAR(255) COLLATE utf8mb4_bin NOT NULL, `body` TEXT COLLATE utf8mb4_0900_as_ci, `blob` TEXT COLLATE utf8mb4_bin, '
            . '`ref` VARCHAR(64) COLLATE utf8mb4_bin NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_ci',
            (new MysqlGrammar())->on(new FakeMysqlPdo(['utf8mb4_0900_as_ci']))->createTable('t', $table->columns()),
        );
    }

    public function test_exact_takes_only_a_text_column(): void
    {
        $this->expectException(\LogicException::class);
        (new Table('t'))->integer('n')->exact();
    }

    public function test_convert_collations_is_one_statement(): void
    {
        $pdo = new FakeMysqlPdo(['utf8mb4_0900_as_ci']);
        $pdo->columns('e', [['title', 'YES', 'Neu', ''], ['code', 'NO', null, '']]);

        (new MysqlGrammar())->on($pdo)->convertCollations($pdo, 'e', [
            'title' => 'VARCHAR(80) COLLATE utf8mb4_0900_as_ci',
            'code' => 'VARCHAR(40) COLLATE utf8mb4_bin',
        ]);

        $this->assertSame([
            "ALTER TABLE `e` MODIFY `title` VARCHAR(80) COLLATE utf8mb4_0900_as_ci NULL DEFAULT 'Neu', MODIFY `code` VARCHAR(40) COLLATE utf8mb4_bin NOT NULL",
        ], $pdo->altered);
    }
}

/**
 * A connection that answers like a MySQL server for the grammar's own questions: the collation
 * probe (accepting the names given, error 1273 for any other) and information_schema.COLUMNS (an
 * attached SQLite database); ALTER statements are recorded, not run.
 */
final class FakeMysqlPdo extends \PDO
{
    /** @var list<string> the collations probed, in order */
    public array $probed = [];

    /** @var list<string> */
    public array $altered = [];

    /** An error code every probe fails with instead (not 1273). */
    public ?int $failWith = null;

    /** @param list<string> $accepted */
    public function __construct(private readonly array $accepted)
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        parent::exec("ATTACH DATABASE ':memory:' AS information_schema");
        parent::exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT, IS_NULLABLE TEXT, COLUMN_DEFAULT TEXT, EXTRA TEXT, ORDINAL_POSITION INTEGER)');
        set_error_handler(static fn (): bool => true, E_DEPRECATED);
        try {
            $this->sqliteCreateFunction('DATABASE', static fn (): string => 'db', 0);
        } finally {
            restore_error_handler();
        }
    }

    /** @param list<array{0: string, 1: string, 2: ?string, 3: string}> $columns name, IS_NULLABLE, COLUMN_DEFAULT, EXTRA */
    public function columns(string $table, array $columns): void
    {
        $insert = $this->prepare("INSERT INTO information_schema.COLUMNS VALUES ('db', ?, ?, ?, ?, ?, ?)");
        foreach ($columns as $i => [$name, $nullable, $default, $extra]) {
            $insert->execute([$table, $name, $nullable, $default, $extra, $i + 1]);
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        if (preg_match("/^SELECT _utf8mb4'a' COLLATE (\\w+)$/", $query, $m) === 1) {
            $this->probed[] = $m[1];
            if ($this->failWith !== null || !in_array($m[1], $this->accepted, true)) {
                $e = new \PDOException("SQLSTATE[HY000]: General error: {$this->failWith} probe");
                $e->errorInfo = ['HY000', $this->failWith ?? 1273, "Unknown collation: '{$m[1]}'"];
                throw $e;
            }
            $query = "SELECT 'a'";
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        if (str_starts_with($statement, 'ALTER TABLE')) {
            $this->altered[] = $statement;

            return 0;
        }

        return parent::exec($statement);
    }
}
