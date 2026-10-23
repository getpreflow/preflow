<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;
use Preflow\Data\Query;
use Preflow\Testing\MysqlTestDatabase;

final class MysqlDriverTest extends TestCase
{
    public function test_mysql_driver_uses_mysql_dialect(): void
    {
        $ref = new \ReflectionClass(MysqlDriver::class);
        $constructor = $ref->getConstructor();
        $params = $constructor->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('pdo', $params[0]->getName());
        $this->assertSame('collector', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional());
    }

    public function test_mysql_driver_extends_pdo_driver(): void
    {
        $this->assertTrue(is_subclass_of(MysqlDriver::class, \Preflow\Data\Driver\PdoDriver::class));
    }

    #[Group('mysql')]
    public function test_mysql_integration(): void
    {
        $pdo = MysqlTestDatabase::pdo();

        $pdo->exec('CREATE TABLE IF NOT EXISTS test_mysql_driver (uuid VARCHAR(36) PRIMARY KEY, title VARCHAR(255), body TEXT)');

        $driver = new MysqlDriver($pdo);

        $driver->save('test_mysql_driver', 'test-1', ['uuid' => 'test-1', 'title' => 'Hello', 'body' => 'World']);
        $row = $driver->findOne('test_mysql_driver', 'test-1');
        $this->assertSame('Hello', $row['title']);
        $this->assertTrue($driver->exists('test_mysql_driver', 'test-1'));

        $driver->delete('test_mysql_driver', 'test-1');
        $this->assertNull($driver->findOne('test_mysql_driver', 'test-1'));

        $pdo->exec('DROP TABLE test_mysql_driver');
    }

    /** Strict mode refuses '' (how PDO sends false) for a TINYINT(1); SQLite took it. */
    #[Group('mysql')]
    public function test_php_booleans_save_and_query_in_a_boolean_column(): void
    {
        $pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($pdo);
        (new Schema($pdo))->create('flags', fn (Table $t) => $t->uuid('uuid')->primary()->boolean('active'));
        $driver = new MysqlDriver($pdo);

        try {
            $driver->save('flags', 'off', ['active' => false]);
            $driver->save('flags', 'on', ['active' => true]);

            $this->assertSame(0, (int) $driver->findOne('flags', 'off')['active']);
            $this->assertSame(1, (int) $driver->findOne('flags', 'on')['active']);
            $off = $driver->findMany('flags', (new Query())->where('active', false));
            $this->assertSame(['off'], array_column($off->items(), 'uuid'));
        } finally {
            MysqlTestDatabase::dropAllTables($pdo);
        }
    }

    #[Group('mysql')]
    public function test_saving_an_unmigrated_field_names_the_fix(): void
    {
        $pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($pdo);
        (new Schema($pdo))->create('notes', fn (Table $t) => $t->uuid('uuid')->primary()->string('title'));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Table notes has no column subtitle; run `preflow migrate`.');
            (new MysqlDriver($pdo))->save('notes', 'n1', ['title' => 'T', 'subtitle' => 'S']);
        } finally {
            MysqlTestDatabase::dropAllTables($pdo);
        }
    }
}
