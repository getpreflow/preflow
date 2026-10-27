<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\UniqueViolation;
use Preflow\Testing\MysqlTestDatabase;

#[Group('mysql')]
final class MysqlClearingTest extends TestCase
{
    private \PDO $pdo;
    private MysqlDriver $driver;

    protected function setUp(): void
    {
        $this->pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($this->pdo);
        $this->pdo->exec('CREATE TABLE games (id VARCHAR(36) PRIMARY KEY, title VARCHAR(191), subtitle TEXT, slug VARCHAR(191))');
        $this->driver = new MysqlDriver($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            MysqlTestDatabase::dropAllTables($this->pdo);
        }
    }

    public function testNullClearsAColumn(): void
    {
        $this->driver->save('games', 'g1', ['title' => 'A', 'subtitle' => 'B'], 'id');
        $this->driver->save('games', 'g1', ['title' => 'A', 'subtitle' => null], 'id');

        $this->assertNull($this->driver->findOne('games', 'g1', 'id')['subtitle']);
    }

    public function testPartialSaveKeepsOtherColumns(): void
    {
        $this->driver->save('games', 'g1', ['title' => 'A', 'subtitle' => 'B'], 'id');
        $this->driver->save('games', 'g1', ['title' => 'C'], 'id');

        $row = $this->driver->findOne('games', 'g1', 'id');
        $this->assertSame('C', $row['title']);
        $this->assertSame('B', $row['subtitle']);
    }

    public function testInsertWritesNullColumns(): void
    {
        $this->driver->save('games', 'g1', ['title' => 'A', 'subtitle' => null], 'id');

        $this->assertNull($this->driver->findOne('games', 'g1', 'id')['subtitle']);
    }

    public function testSaveNeverTouchesAnotherRow(): void
    {
        $this->pdo->exec('CREATE UNIQUE INDEX games_slug ON games (slug)');
        $this->driver->save('games', 'a', ['title' => 'A', 'slug' => 'x'], 'id');
        $this->driver->save('games', 'b', ['title' => 'B', 'slug' => 'y'], 'id');

        try {
            $this->driver->save('games', 'b', ['title' => 'B2', 'slug' => 'x'], 'id');
            $this->fail('A duplicate on a unique index must fail.');
        } catch (UniqueViolation $e) {
            // DataManager turns it into a field error (MysqlUniqueTest).
            $this->assertSame('games_slug', $e->index);
            $this->assertSame('23000', $e->errorInfo[0]);
        }

        $a = $this->driver->findOne('games', 'a', 'id');
        $b = $this->driver->findOne('games', 'b', 'id');
        $this->assertSame(['A', 'x'], [$a['title'], $a['slug']]);
        $this->assertSame(['B', 'y'], [$b['title'], $b['slug']]);
    }

    public function testFirstSaveOfANewIdWithADuplicateUniqueValueIsNotRetried(): void
    {
        $this->pdo->exec('CREATE UNIQUE INDEX games_slug ON games (slug)');
        $this->driver->save('games', 'a', ['title' => 'A', 'slug' => 'x'], 'id');

        $this->expectException(UniqueViolation::class);
        $this->driver->save('games', 'c', ['title' => 'C', 'slug' => 'x'], 'id');
    }

    public function testVersionedFirstSaveRethrowsAUniqueDuplicateThatIsNotTheIdTaken(): void
    {
        $this->pdo->exec('ALTER TABLE games ADD COLUMN _version VARCHAR(20) NULL');
        $this->pdo->exec('CREATE UNIQUE INDEX games_slug ON games (slug)');
        $this->driver->save('games', 'a', ['title' => 'A', 'slug' => 'x'], 'id');

        $this->expectException(\PDOException::class);
        $this->driver->saveIfVersion('games', 'c', ['slug' => 'x', '_version' => 1], '_version', 0, 'id');
    }

    public function testTwoTransactionsSavingNewIdsInOneGapDoNotDeadlock(): void
    {
        $c = MysqlTestDatabase::dataConfig();
        $second = new \PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']),
            $c['username'],
            $c['password'],
            MysqlDriver::connectionOptions(),
        );
        $other = new MysqlDriver($second);
        $this->driver->save('games', 'a', ['title' => 'A'], 'id');
        $this->driver->save('games', 'z', ['title' => 'Z'], 'id');

        $this->pdo->beginTransaction();
        $second->beginTransaction();
        $this->driver->save('games', 'm', ['title' => 'M'], 'id');
        $other->save('games', 'n', ['title' => 'N'], 'id');
        $this->pdo->commit();
        $second->commit();

        $this->assertSame('M', $this->driver->findOne('games', 'm', 'id')['title']);
        $this->assertSame('N', $this->driver->findOne('games', 'n', 'id')['title']);
    }
}
