<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Query;
use Preflow\Data\SortDirection;

final class JsonFileDriverTest extends TestCase
{
    private string $dataDir;
    private JsonFileDriver $driver;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/preflow_json_test_' . uniqid();
        mkdir($this->dataDir, 0755, true);
        $this->driver = new JsonFileDriver($this->dataDir);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->dataDir);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_save_and_find_one(): void
    {
        $this->driver->save('post', 'abc-123', [
            'title' => 'Hello World',
            'status' => 'published',
        ]);

        $result = $this->driver->findOne('post', 'abc-123');

        $this->assertNotNull($result);
        $this->assertSame('Hello World', $result['title']);
        $this->assertSame('published', $result['status']);
    }

    public function test_find_one_returns_null_for_missing(): void
    {
        $result = $this->driver->findOne('post', 'nonexistent');

        $this->assertNull($result);
    }

    public function test_exists(): void
    {
        $this->driver->save('post', 'abc', ['title' => 'Test']);

        $this->assertTrue($this->driver->exists('post', 'abc'));
        $this->assertFalse($this->driver->exists('post', 'xyz'));
    }

    public function test_delete(): void
    {
        $this->driver->save('post', 'abc', ['title' => 'Test']);
        $this->driver->delete('post', 'abc');

        $this->assertFalse($this->driver->exists('post', 'abc'));
    }

    public function test_find_many_returns_all(): void
    {
        $this->driver->save('post', '1', ['title' => 'First', 'status' => 'published']);
        $this->driver->save('post', '2', ['title' => 'Second', 'status' => 'draft']);
        $this->driver->save('post', '3', ['title' => 'Third', 'status' => 'published']);

        $result = $this->driver->findMany('post', new Query());

        $this->assertSame(3, $result->total());
    }

    public function test_find_many_with_where(): void
    {
        $this->driver->save('post', '1', ['title' => 'A', 'status' => 'published']);
        $this->driver->save('post', '2', ['title' => 'B', 'status' => 'draft']);
        $this->driver->save('post', '3', ['title' => 'C', 'status' => 'published']);

        $query = (new Query())->where('status', 'published');
        $result = $this->driver->findMany('post', $query);

        $this->assertSame(2, $result->total());
    }

    public function test_find_many_with_order_by(): void
    {
        $this->driver->save('post', '1', ['title' => 'Banana']);
        $this->driver->save('post', '2', ['title' => 'Apple']);
        $this->driver->save('post', '3', ['title' => 'Cherry']);

        $query = (new Query())->orderBy('title', SortDirection::Asc);
        $result = $this->driver->findMany('post', $query);

        $titles = array_column($result->items(), 'title');
        $this->assertSame(['Apple', 'Banana', 'Cherry'], $titles);
    }

    public function test_find_many_with_limit_and_offset(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->driver->save('post', (string)$i, ['title' => "Post {$i}"]);
        }

        $query = (new Query())->orderBy('title')->limit(2)->offset(1);
        $result = $this->driver->findMany('post', $query);

        $this->assertCount(2, $result->items());
        $this->assertSame(5, $result->total()); // total is unaffected by limit
    }

    public function test_find_many_with_search(): void
    {
        $this->driver->save('post', '1', ['title' => 'PHP Framework', 'body' => 'About PHP']);
        $this->driver->save('post', '2', ['title' => 'Ruby Guide', 'body' => 'About Ruby']);
        $this->driver->save('post', '3', ['title' => 'Go Tutorial', 'body' => 'PHP mentioned']);

        $query = (new Query())->search('PHP', ['title', 'body']);
        $result = $this->driver->findMany('post', $query);

        $this->assertSame(2, $result->total()); // posts 1 and 3
    }

    public function test_save_overwrites_existing(): void
    {
        $this->driver->save('post', 'abc', ['title' => 'Old']);
        $this->driver->save('post', 'abc', ['title' => 'New']);

        $result = $this->driver->findOne('post', 'abc');
        $this->assertSame('New', $result['title']);
    }

    public function test_different_types_are_isolated(): void
    {
        $this->driver->save('post', '1', ['title' => 'Post']);
        $this->driver->save('page', '1', ['title' => 'Page']);

        $posts = $this->driver->findMany('post', new Query());
        $pages = $this->driver->findMany('page', new Query());

        $this->assertSame(1, $posts->total());
        $this->assertSame(1, $pages->total());
        $this->assertSame('Post', $posts->first()['title']);
        $this->assertSame('Page', $pages->first()['title']);
    }

    public function test_find_many_with_not_equals(): void
    {
        $this->driver->save('post', '1', ['status' => 'published']);
        $this->driver->save('post', '2', ['status' => 'draft']);

        $query = (new Query())->where('status', '!=', 'draft');
        $result = $this->driver->findMany('post', $query);

        $this->assertSame(1, $result->total());
    }

    public function test_find_many_with_like(): void
    {
        $this->driver->save('post', '1', ['title' => 'PHP Framework']);
        $this->driver->save('post', '2', ['title' => 'Ruby Guide']);

        $query = (new Query())->where('title', 'LIKE', '%Framework%');
        $result = $this->driver->findMany('post', $query);

        $this->assertSame(1, $result->total());
    }
}
