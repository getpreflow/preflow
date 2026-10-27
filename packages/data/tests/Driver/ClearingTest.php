<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;

final class ClearingTest extends TestCase
{
    private \PDO $pdo;
    private SqliteDriver $sqlite;
    private string $dir;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE games (id TEXT PRIMARY KEY, title TEXT, subtitle TEXT)');
        $this->sqlite = new SqliteDriver($this->pdo);
        $this->dir = sys_get_temp_dir() . '/preflow-clearing-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/games/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/games');
        @rmdir($this->dir);
    }

    public function testNullClearsAColumn(): void
    {
        $this->sqlite->save('games', 'g1', ['title' => 'A', 'subtitle' => 'B'], 'id');
        $this->sqlite->save('games', 'g1', ['title' => 'A', 'subtitle' => null], 'id');

        $this->assertNull($this->sqlite->findOne('games', 'g1', 'id')['subtitle']);
        $this->assertSame('A', $this->sqlite->findOne('games', 'g1', 'id')['title']);
    }

    public function testPartialSaveKeepsOtherColumns(): void
    {
        $this->sqlite->save('games', 'g1', ['title' => 'A', 'subtitle' => 'B'], 'id');
        $this->sqlite->save('games', 'g1', ['title' => 'C'], 'id');

        $row = $this->sqlite->findOne('games', 'g1', 'id');
        $this->assertSame('C', $row['title']);
        $this->assertSame('B', $row['subtitle']);
    }

    public function testInsertWritesNullColumns(): void
    {
        $this->sqlite->save('games', 'g1', ['title' => 'A', 'subtitle' => null], 'id');

        $row = $this->sqlite->findOne('games', 'g1', 'id');
        $this->assertSame('A', $row['title']);
        $this->assertNull($row['subtitle']);
    }

    public function testSaveWithOnlyTheIdInsertsAndLeavesAnExistingRowAlone(): void
    {
        $this->sqlite->save('games', 'g1', ['id' => 'g1'], 'id');
        $this->assertTrue($this->sqlite->exists('games', 'g1', 'id'));

        $this->sqlite->save('games', 'g1', ['title' => 'A'], 'id');
        $this->sqlite->save('games', 'g1', ['id' => 'g1'], 'id');
        $this->assertSame('A', $this->sqlite->findOne('games', 'g1', 'id')['title']);
    }

    public function testSaveNeverTouchesAnotherRow(): void
    {
        $this->pdo->exec('CREATE UNIQUE INDEX games_title ON games (title)');
        $this->sqlite->save('games', 'a', ['title' => 'x', 'subtitle' => 'sa'], 'id');
        $this->sqlite->save('games', 'b', ['title' => 'y', 'subtitle' => 'sb'], 'id');

        try {
            $this->sqlite->save('games', 'b', ['title' => 'x'], 'id');
            $this->fail('A duplicate on a unique index must fail.');
        } catch (\PDOException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
        }

        $this->assertSame('x', $this->sqlite->findOne('games', 'a', 'id')['title']);
        $this->assertSame('sa', $this->sqlite->findOne('games', 'a', 'id')['subtitle']);
        $this->assertSame('y', $this->sqlite->findOne('games', 'b', 'id')['title']);
    }

    public function testJsonNullReadsBackAsEmpty(): void
    {
        $json = new JsonFileDriver($this->dir);
        $json->save('games', 'g1', ['id' => 'g1', 'title' => 'A', 'subtitle' => 'B'], 'id');
        $json->save('games', 'g1', ['id' => 'g1', 'title' => 'A', 'subtitle' => null], 'id');

        $this->assertNull($json->findOne('games', 'g1', 'id')['subtitle']);
    }
}
