<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\Migrator;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;
use Preflow\Testing\MysqlTestDatabase;

#[Group('mysql')]
final class MysqlMigrationsTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            MysqlTestDatabase::dropAllTables($this->pdo);
        }
    }

    /** @return list<string> */
    private function paths(): array
    {
        return glob(dirname(__DIR__, 3) . '/*/migrations');
    }

    public function test_every_package_migration_runs_on_mysql_twice(): void
    {
        $migrator = new Migrator($this->pdo, $this->paths());
        $migrator->migrate();

        $this->assertSame([], $migrator->pending());
        (new Migrator($this->pdo, $this->paths()))->migrate();

        $tables = $this->pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['preflow_migrations', 'preflow_throttle', 'auth_tokens', 'auth_remember', 'code_redemption'] as $t) {
            $this->assertContains($t, $tables);
        }
    }

    public function test_introspection_and_add_column(): void
    {
        (new Migrator($this->pdo, $this->paths()))->migrate();
        $schema = new Schema($this->pdo);

        $this->assertTrue($schema->hasTable('code_redemption'));
        $this->assertFalse($schema->hasTable('nope'));
        $this->assertSame(['id', 'code_id', 'purpose', 'redeemer', 'redeemed_at'], $schema->columns('code_redemption'));

        $schema->addColumn('code_redemption', 'note');
        $columns = $schema->columns('code_redemption');
        $this->assertSame('note', end($columns));

        $type = $this->pdo->query(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'code_redemption' AND COLUMN_NAME = 'note'"
        )->fetchColumn();
        $this->assertSame('longtext', strtolower((string) $type));
    }

    public function test_indexing_a_text_column_throws(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot index TEXT column "t.body" on MySQL: use string() for indexed columns.');

        (new Schema($this->pdo))->create('t', fn (Table $t) => $t->uuid('id')->primary()->text('body')->index());
    }
}
