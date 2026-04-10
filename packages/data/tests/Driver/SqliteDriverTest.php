<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Query;
use Preflow\Data\SortDirection;

final class SqliteDriverTest extends TestCase
{
    private \PDO $pdo;
    private SqliteDriver $driver;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('CREATE TABLE posts (
            uuid TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            status TEXT DEFAULT "draft",
            body TEXT,
            created_at TEXT
        )');

        $this->driver = new SqliteDriver($this->pdo);
    }

    public function test_save_and_find_one(): void
    {
        $this->driver->save('posts', 'abc-123', [
            'title' => 'Hello World',
            'status' => 'published',
        ]);

        $result = $this->driver->findOne('posts', 'abc-123');

        $this->assertNotNull($result);
        $this->assertSame('Hello World', $result['title']);
        $this->assertSame('published', $result['status']);
    }

    public function test_find_one_returns_null(): void
    {
        $this->assertNull($this->driver->findOne('posts', 'nonexistent'));
    }

    public function test_exists(): void
    {
        $this->driver->save('posts', 'abc', ['title' => 'Test']);

        $this->assertTrue($this->driver->exists('posts', 'abc'));
        $this->assertFalse($this->driver->exists('posts', 'xyz'));
    }

    public function test_delete(): void
    {
        $this->driver->save('posts', 'abc', ['title' => 'Test']);
        $this->driver->delete('posts', 'abc');

        $this->assertFalse($this->driver->exists('posts', 'abc'));
    }

    public function test_find_many(): void
    {
        $this->driver->save('posts', '1', ['title' => 'First', 'status' => 'published']);
        $this->driver->save('posts', '2', ['title' => 'Second', 'status' => 'draft']);
        $this->driver->save('posts', '3', ['title' => 'Third', 'status' => 'published']);

        $result = $this->driver->findMany('posts', new Query());
        $this->assertSame(3, $result->total());
    }

    public function test_find_many_with_where(): void
    {
        $this->driver->save('posts', '1', ['title' => 'A', 'status' => 'published']);
        $this->driver->save('posts', '2', ['title' => 'B', 'status' => 'draft']);

        $query = (new Query())->where('status', 'published');
        $result = $this->driver->findMany('posts', $query);

        $this->assertSame(1, $result->total());
    }

    public function test_find_many_with_order(): void
    {
        $this->driver->save('posts', '1', ['title' => 'Banana']);
        $this->driver->save('posts', '2', ['title' => 'Apple']);
        $this->driver->save('posts', '3', ['title' => 'Cherry']);

        $query = (new Query())->orderBy('title', SortDirection::Asc);
        $result = $this->driver->findMany('posts', $query);

        $titles = array_column($result->items(), 'title');
        $this->assertSame(['Apple', 'Banana', 'Cherry'], $titles);
    }

    public function test_find_many_with_limit_offset(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->driver->save('posts', (string)$i, ['title' => "Post {$i}"]);
        }

        $query = (new Query())->orderBy('title')->limit(2)->offset(1);
        $result = $this->driver->findMany('posts', $query);

        $this->assertCount(2, $result->items());
        $this->assertSame(5, $result->total());
    }

    public function test_find_many_with_search(): void
    {
        $this->driver->save('posts', '1', ['title' => 'PHP Guide', 'body' => 'About PHP']);
        $this->driver->save('posts', '2', ['title' => 'Ruby Guide', 'body' => 'About Ruby']);

        $query = (new Query())->search('PHP', ['title', 'body']);
        $result = $this->driver->findMany('posts', $query);

        $this->assertSame(1, $result->total());
    }

    public function test_save_updates_existing(): void
    {
        $this->driver->save('posts', 'abc', ['title' => 'Old']);
        $this->driver->save('posts', 'abc', ['title' => 'New']);

        $result = $this->driver->findOne('posts', 'abc');
        $this->assertSame('New', $result['title']);
    }
}
