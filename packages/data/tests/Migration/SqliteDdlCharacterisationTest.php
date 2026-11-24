<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\SqliteDialect;
use Preflow\Data\Migration\Grammar\SqliteGrammar;
use Preflow\Data\Migration\Migrator;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;

/**
 * Pins the exact SQLite DDL of every package migration, so the grammar refactor
 * (and later changes) can never alter what existing sites already have. Since 0.78 string()
 * and text() columns that are not exact() declare COLLATE preflow_text (tables that sites
 * made before keep theirs: their migrations do not run again); machine values are exact().
 */
final class SqliteDdlCharacterisationTest extends TestCase
{
    public function test_package_migrations_produce_unchanged_sqlite_ddl(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        SqliteDialect::prepare($pdo);

        // The starters are apps, not packages: their migrations repeat each other's names
        // (starter and starter-folio both create users), so they are not part of this pin.
        $paths = array_values(array_filter(
            glob(dirname(__DIR__, 3) . '/*/migrations'),
            static fn (string $path): bool => !str_starts_with(basename(dirname($path)), 'starter'),
        ));
        $this->assertNotEmpty($paths);
        (new Migrator($pdo, $paths))->migrate();

        $actual = $pdo->query('SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY name')
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame([
            ['type' => 'table', 'name' => 'auth_remember', 'sql' => 'CREATE TABLE `auth_remember` (
    `id` TEXT PRIMARY KEY,
    `guard` TEXT NOT NULL,
    `user_id` TEXT NOT NULL,
    `validator_hash` TEXT NOT NULL,
    `previous_hash` TEXT,
    `rotated_at` INTEGER,
    `expires_at` INTEGER NOT NULL
)'],
            ['type' => 'table', 'name' => 'auth_tokens', 'sql' => 'CREATE TABLE `auth_tokens` (
    `id` TEXT PRIMARY KEY,
    `purpose` TEXT NOT NULL,
    `subject` TEXT NOT NULL,
    `expires_at` INTEGER NOT NULL
)'],
            ['type' => 'table', 'name' => 'code_redemption', 'sql' => 'CREATE TABLE `code_redemption` (
    `id` TEXT PRIMARY KEY,
    `code_id` TEXT NOT NULL,
    `purpose` TEXT NOT NULL,
    `redeemer` TEXT,
    `redeemed_at` INTEGER NOT NULL
)'],
            ['type' => 'index', 'name' => 'idx_code_redemption_code_id', 'sql' => 'CREATE INDEX `idx_code_redemption_code_id` ON `code_redemption` (`code_id`)'],
            // Since 0.78: the columns' intent each migration records (MigrationColumns).
            ['type' => 'table', 'name' => 'preflow_migration_columns', 'sql' => 'CREATE TABLE `preflow_migration_columns` (
    `id` TEXT PRIMARY KEY,
    `table_name` TEXT NOT NULL,
    `column_name` TEXT NOT NULL,
    `intent` TEXT NOT NULL
)'],
            ['type' => 'table', 'name' => 'preflow_migrations', 'sql' => 'CREATE TABLE preflow_migrations (
            migration TEXT PRIMARY KEY,
            ran_at TEXT NOT NULL
        )'],
            ['type' => 'table', 'name' => 'preflow_throttle', 'sql' => 'CREATE TABLE `preflow_throttle` (
    `id` TEXT PRIMARY KEY,
    `purpose` TEXT NOT NULL,
    `hits` INTEGER NOT NULL,
    `window_start` INTEGER NOT NULL
, `expires_at` INTEGER)'],
        ], $actual);
    }

    /** Since 0.78 string() and text() columns (not exact, not primary) and text fields' record columns compare by the rule. */
    public function test_text_columns_declare_preflow_text(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        SqliteDialect::prepare($pdo);
        (new Schema($pdo))->create('t', function (Table $table): void {
            $table->string('id')->primary();
            $table->string('email');
            $table->string('key')->exact();
            $table->text('body')->nullable();
            $table->uuid('ref');
            $table->integer('n');
        });

        $this->assertSame('CREATE TABLE `t` (
    `id` TEXT PRIMARY KEY,
    `email` TEXT COLLATE preflow_text NOT NULL,
    `key` TEXT NOT NULL,
    `body` TEXT COLLATE preflow_text,
    `ref` TEXT NOT NULL,
    `n` INTEGER NOT NULL
)', $pdo->query("SELECT sql FROM sqlite_master WHERE name = 't'")->fetchColumn());

        $grammar = (new SqliteGrammar())->on($pdo);
        $this->assertSame(
            'CREATE TABLE `e` (`uuid` TEXT PRIMARY KEY, `title` TEXT COLLATE preflow_text, `blocks` TEXT, `n` NUMERIC)',
            $grammar->createRecordTable('e', 'uuid', [new ColumnSpec('title', ColumnKind::Short, text: true), new ColumnSpec('blocks'), new ColumnSpec('n', ColumnKind::Number)]),
        );
        $this->assertSame('TEXT COLLATE preflow_text', $grammar->textColumnType(ColumnKind::Text));
        $this->assertSame('TEXT COLLATE preflow_text', $grammar->textType('string'));
        $this->assertSame('ALTER TABLE `e` ADD COLUMN `x` TEXT COLLATE preflow_text', $grammar->addColumn('e', 'x', $grammar->textColumnType(ColumnKind::Short)));
        $this->assertSame('CREATE TABLE `e` (`uuid` TEXT PRIMARY KEY, `title` TEXT)', (new SqliteGrammar())->createRecordTable('e', 'uuid', [new ColumnSpec('title', text: true)]), 'an unbound grammar: as before');
    }
}
