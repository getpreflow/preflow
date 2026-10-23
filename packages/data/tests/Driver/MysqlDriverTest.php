<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDriver;
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
}
