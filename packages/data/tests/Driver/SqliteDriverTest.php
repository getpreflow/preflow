<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

require_once __DIR__ . '/OrGroupScenarios.php';
require_once __DIR__ . '/WhereScenarios.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Query;
use Preflow\Data\SortDirection;

final class SqliteDriverTest extends TestCase
{
    use OrGroupScenarios;
    use WhereScenarios;

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

    public function test_total_is_right_with_and_without_a_full_page(): void
    {
        foreach (['a', 'b', 'c'] as $i) {
            $this->driver->save('posts', $i, ['uuid' => $i, 'title' => "T{$i}"]);
        }
        $page = fn (int $limit, int $offset) => $this->driver->findMany('posts', (new Query())->limit($limit)->offset($offset));

        $this->assertSame([2, 3], [count($page(2, 0)->items()), $page(2, 0)->total()]);
        $this->assertSame([3, 3], [count($page(4, 0)->items()), $page(4, 0)->total()]);
        $this->assertSame([3, 3], [count($page(3, 0)->items()), $page(3, 0)->total()]);
        $this->assertSame([1, 3], [count($page(2, 2)->items()), $page(2, 2)->total()]);
        $this->assertSame([0, 3], [count($page(2, 5)->items()), $page(2, 5)->total()]);
        $this->assertSame(3, $this->driver->findMany('posts', new Query())->total());
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

    public function test_missing_table_on_read_names_migrate(): void
    {
        foreach ([
            fn () => $this->driver->findOne('nope', 'x'),
            fn () => $this->driver->findMany('nope', new \Preflow\Data\Query()),
        ] as $read) {
            try {
                $read();
                $this->fail('Expected a RuntimeException.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Table nope does not exist; run `preflow migrate`.', $e->getMessage());
                $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
            }
        }
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

    public function test_save_with_empty_id_uses_auto_increment(): void
    {
        $this->pdo->exec('CREATE TABLE items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )');

        $driver = new SqliteDriver($this->pdo);

        $driver->save('items', 0, ['name' => 'First'], 'id');
        $driver->save('items', 0, ['name' => 'Second'], 'id');

        $result = $driver->findMany('items', new Query());
        $this->assertSame(2, $result->total());

        $names = array_column($result->items(), 'name');
        $this->assertContains('First', $names);
        $this->assertContains('Second', $names);

        $ids = array_column($result->items(), 'id');
        $this->assertNotEquals($ids[0], $ids[1]);
    }

    public function test_save_with_empty_id_returns_last_insert_id(): void
    {
        $this->pdo->exec('CREATE TABLE items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )');

        $driver = new SqliteDriver($this->pdo);

        $driver->save('items', 0, ['name' => 'Hello'], 'id');
        $newId = $driver->lastInsertId();

        $this->assertIsInt($newId);
        $this->assertGreaterThan(0, $newId);

        $result = $driver->findOne('items', $newId, 'id');
        $this->assertNotNull($result);
        $this->assertSame('Hello', $result['name']);
    }

    public function test_exposes_its_pdo_connection(): void
    {
        $this->assertSame($this->pdo, $this->driver->pdo());
    }

    public function test_execute_returns_affected_rows(): void
    {
        $this->driver->save('posts', 'a', ['title' => 'A']);
        $this->driver->save('posts', 'b', ['title' => 'B']);
        $this->driver->save('posts', 'c', ['title' => 'C', 'status' => 'published']);

        $this->assertSame(2, $this->driver->execute('UPDATE posts SET status = ? WHERE status = ?', ['published', 'draft']));
        $this->assertSame('published', $this->driver->findOne('posts', 'a')['status']);
        $this->assertSame(0, $this->driver->execute('DELETE FROM posts WHERE uuid = ?', ['nope']));

        $dm = new \Preflow\Data\DataManager([
            'default' => $this->driver,
            'json' => new \Preflow\Data\Driver\JsonFileDriver(sys_get_temp_dir()),
        ]);
        $this->assertSame(1, $dm->execute('DELETE FROM posts WHERE uuid = ?', ['c']));
        $this->assertNull($this->driver->findOne('posts', 'c'));

        $this->expectException(\LogicException::class);
        $dm->execute('DELETE FROM posts', [], 'json');
    }

    public function test_or_group_contains_is_case_insensitive(): void
    {
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, tags TEXT)');
        $driver = $this->driver;
        $this->checkContainsIsCaseInsensitive($driver);
    }

    public function test_or_group_contains_takes_special_chars_literally(): void
    {
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, tags TEXT)');
        $driver = $this->driver;
        $this->checkContainsTakesSpecialCharsLiterally($driver);
    }

    public function test_or_group_contains_finds_umlauts(): void
    {
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, tags TEXT)');
        $driver = $this->driver;
        $this->checkContainsFindsUmlauts($driver);
    }

    public function test_or_group_in_matches_listed_values_and_empty_matches_nothing(): void
    {
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, tags TEXT)');
        $driver = $this->driver;
        $this->checkIn($driver);
    }

    public function test_or_group_has_reads_json_arrays_single_ids_empty_and_invalid_json(): void
    {
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, tags TEXT)');
        $driver = $this->driver;
        $this->checkHas($driver);
    }

    public function test_or_group_or_group_inside_and_two_groups_are_anded(): void
    {
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, tags TEXT)');
        $driver = $this->driver;
        $this->checkOrWithinAndAcrossGroups($driver);
    }

    public function test_or_group_count_equals_rows_ignoring_limit(): void
    {
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, tags TEXT)');
        $driver = $this->driver;
        $this->checkCountEqualsRowsWithLimit($driver);
    }

    public function test_or_group_or_where_chain_is_grouped_before_groups_are_anded(): void
    {
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, tags TEXT)');
        $driver = $this->driver;
        $this->checkOrWhereChainIsGroupedBeforeGroupsAreAnded($driver);
    }

    public function test_where_null_finds_the_null_row(): void
    {
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, tags TEXT)');
        $this->checkWhereNullFindsTheNullRow($this->driver);
    }

    public function test_where_in_and_not_in(): void
    {
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, tags TEXT)');
        $this->checkWhereInAndNotIn($this->driver);
    }

    public function test_where_like_not_like_and_angle_not_equals(): void
    {
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, tags TEXT)');
        $this->checkWhereLikeNotLikeAndAngleNotEquals($this->driver);
    }

    public function test_a_quote_in_an_identifier_stays_inside_the_identifier(): void
    {
        $this->pdo->exec('CREATE TABLE "it""ems" (uuid TEXT PRIMARY KEY, title TEXT)');
        $this->driver->save('it"ems', 'a', ['uuid' => 'a', 'title' => 'T']);

        $this->assertSame('T', $this->driver->findOne('it"ems', 'a')['title']);
    }
}
