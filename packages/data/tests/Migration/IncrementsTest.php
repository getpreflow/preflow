<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Migration\Grammar\MysqlGrammar;
use Preflow\Data\Migration\Grammar\SqliteGrammar;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;
use Preflow\Data\Model;

#[Entity(table: 'visits', storage: 'default')]
class IncrementsVisit extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    public string $page = '';
}

/** Table::increments(): an integer primary key the database numbers itself (0.79). */
final class IncrementsTest extends TestCase
{
    public function test_increments_declares_an_auto_numbered_integer_primary_key(): void
    {
        $table = new Table('visits');
        $table->increments();
        $table->increments('number');

        $columns = $table->columns();
        $this->assertSame('id', $columns[0]['name']);
        $this->assertSame('integer', $columns[0]['kind']);
        $this->assertTrue($columns[0]['primary']);
        $this->assertTrue($columns[0]['auto']);
        $this->assertSame('number', $columns[1]['name']);
    }

    public function test_a_plain_integer_primary_is_not_auto_numbered(): void
    {
        $table = new Table('visits');
        $table->integer('id')->primary();

        $this->assertFalse($table->columns()[0]['auto']);
    }

    public function test_mysql_ddl(): void
    {
        $table = new Table('visits');
        $table->increments()->string('page');
        $sql = (new MysqlGrammar())->createTable('visits', $table->columns());

        $this->assertStringContainsString('`id` BIGINT AUTO_INCREMENT PRIMARY KEY', $sql);
    }

    public function test_mysql_integer_primary_stays_without_auto_increment(): void
    {
        $table = new Table('visits');
        $table->integer('id')->primary();

        $this->assertStringNotContainsString('AUTO_INCREMENT', (new MysqlGrammar())->createTable('visits', $table->columns()));
    }

    public function test_sqlite_ddl_is_a_rowid_alias_without_the_autoincrement_keyword(): void
    {
        $table = new Table('visits');
        $table->increments()->string('page');
        $sql = (new SqliteGrammar())->createTable('visits', $table->columns());

        $this->assertStringContainsString('`id` INTEGER PRIMARY KEY', $sql);
        $this->assertStringNotContainsString('AUTOINCREMENT', strtoupper($sql));
    }

    public function test_sqlite_round_trip_and_the_table_can_still_be_rebuilt(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        \Preflow\Data\Driver\SqliteDialect::prepare($pdo);
        $schema = new Schema($pdo);
        $schema->create('visits', fn (Table $t) => $t->increments()->string('page')->nullable());
        $dm = new DataManager(['default' => new SqliteDriver($pdo)], 'default');

        $first = new IncrementsVisit();
        $first->page = '/a';
        $dm->insert($first);
        $second = new IncrementsVisit();
        $second->page = '/b';
        $dm->insert($second);
        $this->assertSame([1, 2], [$first->id, $second->id]);

        $schema->convertColumn('visits', fn (Table $t) => $t->string('page', 100)->nullable());
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM visits')->fetchColumn());
    }
}
