<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

require_once __DIR__ . '/OrGroupScenarios.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Query;
use Preflow\Data\SortDirection;

final class JsonFileDriverTest extends TestCase
{
    use OrGroupScenarios;

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

    public function test_save_is_atomic_and_leaves_no_temp_files(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->driver->save('post', 'p1', ['uuid' => 'p1', 'n' => $i]);
        }

        $files = array_values(array_diff(scandir($this->dataDir . '/post'), ['.', '..']));
        $this->assertSame(['p1.json'], $files);
        $this->assertSame(20, $this->driver->findOne('post', 'p1')['n']);
    }

    public function test_find_many_ignores_temp_files(): void
    {
        $this->driver->save('post', 'p1', ['uuid' => 'p1']);
        file_put_contents($this->dataDir . '/post/p2.json.tmp-1', '{"uuid":"p2"'); // a write in progress

        $this->assertCount(1, $this->driver->findMany('post', new Query())->items());
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

    public function test_or_group_contains_is_case_insensitive(): void
    {
        $driver = $this->driver;
        $this->checkContainsIsCaseInsensitive($driver);
    }

    public function test_or_group_contains_takes_special_chars_literally(): void
    {
        $driver = $this->driver;
        $this->checkContainsTakesSpecialCharsLiterally($driver);
    }

    public function test_or_group_contains_finds_umlauts(): void
    {
        $driver = $this->driver;
        $this->checkContainsFindsUmlauts($driver);
    }

    public function test_or_group_in_matches_listed_values_and_empty_matches_nothing(): void
    {
        $driver = $this->driver;
        $this->checkIn($driver);
    }

    public function test_or_group_has_reads_json_arrays_single_ids_empty_and_invalid_json(): void
    {
        $driver = $this->driver;
        $this->checkHas($driver);
    }

    public function test_or_group_or_group_inside_and_two_groups_are_anded(): void
    {
        $driver = $this->driver;
        $this->checkOrWithinAndAcrossGroups($driver);
    }

    public function test_or_group_count_equals_rows_ignoring_limit(): void
    {
        $driver = $this->driver;
        $this->checkCountEqualsRowsWithLimit($driver);
    }

    public function test_or_group_or_where_chain_is_grouped_before_groups_are_anded(): void
    {
        $driver = $this->driver;
        $this->checkOrWhereChainIsGroupedBeforeGroupsAreAnded($driver);
    }

    private function cached(): array
    {
        $cacheDir = $this->dataDir . '-cache';
        return [new JsonFileDriver($this->dataDir, $cacheDir), $cacheDir];
    }

    /** The data path's cache (or generation) file of the type, named per data path. */
    private function cacheFile(string $cacheDir, string $type, string $ext = 'json'): string
    {
        $found = glob($cacheDir . '/json-' . $type . '-*.' . $ext) ?: [];
        $this->assertLessThanOrEqual(1, count($found));

        return $found[0] ?? $cacheDir . '/json-' . $type . '-(none).' . $ext;
    }

    /** The type's directory last changed a while ago: a cache built now is past that second. */
    private function settle(string $type): void
    {
        touch($this->dataDir . '/' . $type, time() - 100);
        clearstatcache();
    }

    public function test_cache_second_query_reads_the_cache(): void
    {
        [$driver, $cacheDir] = $this->cached();
        $driver->save('post', 'p1', ['uuid' => 'p1', 'title' => 'Original']);
        $this->settle('post');
        $this->assertSame('Original', $driver->findMany('post', new Query())->items()[0]['title']);
        $this->assertFileExists($this->cacheFile($cacheDir, 'post', 'json'));

        $cache = file_get_contents($this->cacheFile($cacheDir, 'post', 'json'));
        file_put_contents($this->cacheFile($cacheDir, 'post', 'json'), str_replace('Original', 'FromCache', $cache));

        $this->assertSame('FromCache', $driver->findMany('post', new Query())->items()[0]['title']);
        $this->deleteDir($cacheDir);
    }

    public function test_cache_shows_save_and_delete(): void
    {
        [$driver, $cacheDir] = $this->cached();
        $driver->save('post', 'p1', ['uuid' => 'p1', 'title' => 'A']);
        $this->assertCount(1, $driver->findMany('post', new Query())->items());

        $driver->save('post', 'p2', ['uuid' => 'p2', 'title' => 'B']);
        $driver->save('post', 'p1', ['uuid' => 'p1', 'title' => 'A2']);
        $titles = array_column($driver->findMany('post', new Query())->items(), 'title');
        sort($titles);
        $this->assertSame(['A2', 'B'], $titles);

        $driver->delete('post', 'p1');
        $this->assertSame(['B'], array_column($driver->findMany('post', new Query())->items(), 'title'));
        $this->deleteDir($cacheDir);
    }

    public function test_cache_sees_files_added_and_removed_outside_the_driver(): void
    {
        [$driver, $cacheDir] = $this->cached();
        $driver->save('post', 'p1', ['uuid' => 'p1', 'title' => 'A']);
        $this->settle('post');
        $driver->findMany('post', new Query());

        file_put_contents($this->dataDir . '/post/p2.json', '{"uuid":"p2","title":"B"}');
        $this->assertCount(2, $driver->findMany('post', new Query())->items());

        $this->settle('post');
        $driver->findMany('post', new Query());
        unlink($this->dataDir . '/post/p2.json');
        $this->assertSame(['A'], array_column($driver->findMany('post', new Query())->items(), 'title'));
        $this->deleteDir($cacheDir);
    }

    public function test_json_cache_validation_does_not_stat_every_record(): void
    {
        [$driver, $cacheDir] = $this->cached();
        $driver->save('post', 'p1', ['uuid' => 'p1', 'title' => 'A']);
        $driver->save('post', 'p2', ['uuid' => 'p2', 'title' => 'B']);
        $this->settle('post');
        $driver->findMany('post', new Query()); // builds the cache

        // A record file rewritten in place outside a driver (same size, newer mtime) is not looked at:
        // the documented exception, which needs cache:clear. Every record file would have been
        // stat-ed by the old check and the change noticed.
        $file = $this->dataDir . '/post/p1.json';
        $size = filesize($file);
        $json = (string) file_get_contents($file);
        file_put_contents($file, str_replace('"A"', '"Z"', $json));
        touch($file, time() + 10);
        touch($this->dataDir . '/post', time() - 100);
        clearstatcache();
        $this->assertSame($size, filesize($file));

        $titles = array_column($driver->findMany('post', new Query())->items(), 'title');
        sort($titles);
        $this->assertSame(['A', 'B'], $titles);

        $driver->clearCache();
        $titles = array_column($driver->findMany('post', new Query())->items(), 'title');
        sort($titles);
        $this->assertSame(['B', 'Z'], $titles);
        $this->deleteDir($cacheDir);
    }

    public function test_a_record_added_removed_or_saved_by_another_driver_invalidates_a_settled_cache(): void
    {
        [$driver, $cacheDir] = $this->cached();
        $other = new JsonFileDriver($this->dataDir, $cacheDir); // another process, same data path
        $driver->save('post', 'p1', ['uuid' => 'p1', 'title' => 'A']);
        $this->settle('post');
        $driver->findMany('post', new Query());

        $other->save('post', 'p2', ['uuid' => 'p2', 'title' => 'B']);
        $this->assertCount(2, $driver->findMany('post', new Query())->items());

        $this->settle('post');
        $driver->findMany('post', new Query());
        $other->save('post', 'p1', ['uuid' => 'p1', 'title' => 'A2']); // in place, same size, directory mtime pushed back
        $this->settle('post');
        $titles = array_column($driver->findMany('post', new Query())->items(), 'title');
        sort($titles);
        $this->assertSame(['A2', 'B'], $titles);

        $this->settle('post');
        $driver->findMany('post', new Query());
        unlink($this->dataDir . '/post/p2.json'); // removed outside any driver: the directory changes
        $this->assertSame(['A2'], array_column($driver->findMany('post', new Query())->items(), 'title'));
        $this->deleteDir($cacheDir);
    }

    public function test_a_file_added_in_the_second_the_cache_was_built_is_still_seen(): void
    {
        [$driver, $cacheDir] = $this->cached();
        $driver->save('post', 'p1', ['uuid' => 'p1', 'title' => 'A']);
        $time = time() - 5;
        touch($this->dataDir . '/post', $time);
        $driver->findMany('post', new Query());
        touch($this->cacheFile($cacheDir, 'post', 'json'), $time); // built in the second the directory last changed

        file_put_contents($this->dataDir . '/post/p2.json', '{"uuid":"p2","title":"B"}');
        touch($this->dataDir . '/post', $time); // the directory's whole-second mtime did not move
        clearstatcache();

        $this->assertCount(2, $driver->findMany('post', new Query())->items());
        $this->deleteDir($cacheDir);
    }

    public function test_cache_written_by_a_reader_that_started_before_a_save_is_ignored(): void
    {
        [$driver, $cacheDir] = $this->cached();
        $driver->save('post', 'p1', ['uuid' => 'p1', 'title' => 'A']);
        $driver->findMany('post', new Query());
        $stale = (string) file_get_contents($this->cacheFile($cacheDir, 'post', 'json'));
        $file = $this->dataDir . '/post/p1.json';
        $dir = $this->dataDir . '/post';
        clearstatcache();
        [$fileTime, $dirTime] = [filemtime($file), filemtime($dir)];

        // A same-size save within the same second (forced here) ...
        $driver->save('post', 'p1', ['uuid' => 'p1', 'title' => 'B']);
        touch($file, $fileTime);
        touch($dir, $dirTime);
        // ... and a reader that read the records before it writes its cache after the save's clearCache().
        file_put_contents($this->cacheFile($cacheDir, 'post', 'json'), $stale);

        $this->assertSame('B', $driver->findMany('post', new Query())->items()[0]['title']);
        $this->deleteDir($cacheDir);
    }

    public function test_cache_generation_moves_on_save_and_delete_only(): void
    {
        [$driver, $cacheDir] = $this->cached();
        $driver->save('post', 'p1', ['uuid' => 'p1', 'title' => 'A']);
        $first = (string) file_get_contents($this->cacheFile($cacheDir, 'post', 'gen'));
        $driver->findMany('post', new Query());
        $this->assertSame($first, file_get_contents($this->cacheFile($cacheDir, 'post', 'gen')));

        $driver->save('post', 'p1', ['uuid' => 'p1', 'title' => 'B']);
        $second = (string) file_get_contents($this->cacheFile($cacheDir, 'post', 'gen'));
        $this->assertNotSame($first, $second);
        $driver->delete('post', 'p1');
        $this->assertNotSame($second, file_get_contents($this->cacheFile($cacheDir, 'post', 'gen')));
        $this->assertSame([], glob($cacheDir . '/*.tmp-*'));

        $driver->clearCache();
        $this->assertFileExists($this->cacheFile($cacheDir, 'post', 'gen'), 'clearing the cache keeps the generation');
        $this->deleteDir($cacheDir);
    }

    public function test_cache_clear_cache_and_corrupt_cache(): void
    {
        [$driver, $cacheDir] = $this->cached();
        $driver->save('post', 'p1', ['uuid' => 'p1']);
        $driver->save('page', 'g1', ['uuid' => 'g1']);
        $driver->findMany('post', new Query());
        $driver->findMany('page', new Query());

        $driver->clearCache('post');
        $this->assertFileDoesNotExist($this->cacheFile($cacheDir, 'post', 'json'));
        $this->assertFileExists($this->cacheFile($cacheDir, 'page', 'json'));
        $driver->clearCache();
        $this->assertFileDoesNotExist($this->cacheFile($cacheDir, 'page', 'json'));

        $driver->findMany('post', new Query());
        file_put_contents($this->cacheFile($cacheDir, 'post', 'json'), '{not json');
        $this->assertCount(1, $driver->findMany('post', new Query())->items());
        $this->assertSame($cacheDir, $driver->cacheDir());
        $this->deleteDir($cacheDir);
    }

    public function test_two_data_paths_never_share_a_cache(): void
    {
        $cacheDir = $this->dataDir . '-cache';
        $otherDir = $this->dataDir . '-other';
        $a = new JsonFileDriver($this->dataDir, $cacheDir);
        $b = new JsonFileDriver($otherDir, $cacheDir);
        $a->save('post', 'p1', ['uuid' => 'p1', 'title' => 'AAAA']);
        $b->save('post', 'p1', ['uuid' => 'p1', 'title' => 'BBBB']);
        // Same count, size and mtimes: the signatures alone cannot tell the two paths apart.
        $time = time() - 100;
        foreach ([$this->dataDir, $otherDir] as $dir) {
            touch($dir . '/post/p1.json', $time);
            touch($dir . '/post', $time);
        }

        $this->assertSame('AAAA', $a->findMany('post', new Query())->items()[0]['title']);
        $this->assertSame('BBBB', $b->findMany('post', new Query())->items()[0]['title']);
        $this->assertSame('AAAA', $a->findMany('post', new Query())->items()[0]['title']);
        $this->deleteDir($cacheDir);
        $this->deleteDir($otherDir);
    }

    public function test_a_save_in_one_data_path_keeps_the_other_paths_cache(): void
    {
        $cacheDir = $this->dataDir . '-cache';
        $otherDir = $this->dataDir . '-other';
        $a = new JsonFileDriver($this->dataDir, $cacheDir);
        $b = new JsonFileDriver($otherDir, $cacheDir);
        $b->save('post', 'p1', ['uuid' => 'p1', 'title' => 'B']);
        $b->findMany('post', new Query());
        $before = array_map('file_get_contents', glob($cacheDir . '/json-post-*'));

        $a->save('post', 'p1', ['uuid' => 'p1', 'title' => 'A']);
        $a->clearCache();

        $bFiles = array_values(array_filter(glob($cacheDir . '/json-post-*'), static fn (string $f): bool => in_array(file_get_contents($f), $before, true)));
        $this->assertCount(2, $bFiles, "B's cache and generation are untouched by A's save and clearCache()");
        $this->assertCount(2, $before);
        $this->assertSame('B', $b->findMany('post', new Query())->items()[0]['title']);
        $this->deleteDir($cacheDir);
        $this->deleteDir($otherDir);
    }

    public function test_every_spelling_of_a_data_path_shares_one_cache(): void
    {
        $cacheDir = $this->dataDir . '-cache';
        $link = $this->dataDir . '-link';
        symlink($this->dataDir, $link);
        $writer = new JsonFileDriver($this->dataDir, $cacheDir);
        $writer->save('post', 'p1', ['uuid' => 'p1', 'title' => 'A']);
        $this->settle('post');
        $writer->findMany('post', new Query());
        $cacheFile = $this->cacheFile($cacheDir, 'post');
        // Marks the cache: a reader that rewrites it instead of using it drops the mark.
        file_put_contents($cacheFile, str_replace('"A"', '"Cached"', (string) file_get_contents($cacheFile)));
        $marked = file_get_contents($cacheFile);

        foreach ([$link, $this->dataDir . '/', $this->dataDir . '/../' . basename($this->dataDir)] as $spelling) {
            $reader = new JsonFileDriver($spelling, $cacheDir);
            $this->assertSame('Cached', $reader->findMany('post', new Query())->items()[0]['title'], $spelling);
            $this->assertSame($marked, file_get_contents($cacheFile), $spelling);
        }
        $this->assertCount(1, glob($cacheDir . '/json-post-*.json'));
        unlink($link);
        $this->deleteDir($cacheDir);
    }

    public function test_without_cache_dir_no_files_are_written(): void
    {
        $this->driver->save('post', 'p1', ['uuid' => 'p1']);
        $this->driver->findMany('post', new Query());
        $this->driver->clearCache();

        $this->assertNull($this->driver->cacheDir());
        $this->assertSame(['post'], array_values(array_diff(scandir($this->dataDir), ['.', '..'])));
    }

    /** @return array<string, array{string}> */
    public static function unsafeIds(): array
    {
        return [
            'parent dir' => ['../staff/s1'],
            'slash' => ['a/b'],
            'backslash' => ['a\\b'],
            'nul' => ["a\0b"],
            'dot dot' => ['..'],
            'dot' => ['.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeIds')]
    public function test_unsafe_ids_never_reach_a_file_path(string $id): void
    {
        $this->driver->save('staff', 's1', ['uuid' => 's1', 'password_hash' => 'h']);
        $this->driver->save('post', 'p1', ['uuid' => 'p1']);
        $staffFile = $this->dataDir . '/staff/s1.json';
        $before = file_get_contents($staffFile);

        $this->assertNull($this->driver->findOne('post', $id));
        $this->assertFalse($this->driver->exists('post', $id));

        try {
            $this->driver->save('post', $id, ['uuid' => $id, 'title' => 'x']);
            $this->fail('save accepted an unsafe id');
        } catch (\InvalidArgumentException) {
        }
        try {
            $this->driver->delete('post', $id);
            $this->fail('delete accepted an unsafe id');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame($before, file_get_contents($staffFile));
        $this->assertSame(['p1.json'], array_values(array_diff(scandir($this->dataDir . '/post'), ['.', '..'])));
        $this->assertSame(['s1.json'], array_values(array_diff(scandir($this->dataDir . '/staff'), ['.', '..'])));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeIds')]
    public function test_unsafe_types_never_reach_a_file_path(string $type): void
    {
        $this->driver->save('staff', 's1', ['uuid' => 's1', 'password_hash' => 'h']);

        $this->assertNull($this->driver->findOne($type, 's1'));
        $this->assertFalse($this->driver->exists($type, 's1'));
        $this->assertSame([], $this->driver->findMany($type, new Query())->items());
        $this->expectException(\InvalidArgumentException::class);
        $this->driver->save($type, 's1', ['uuid' => 's1']);
    }

    public function test_a_type_with_a_path_in_it_reads_nothing(): void
    {
        $this->driver->save('staff', 's1', ['uuid' => 's1', 'password_hash' => 'h']);
        $this->driver->save('post', 'p1', ['uuid' => 'p1']);

        $this->assertNull($this->driver->findOne('post/../staff', 's1'));
        $this->assertSame([], $this->driver->findMany('post/../staff', new Query())->items());
    }

    public function test_ids_with_dots_inside_stay_usable(): void
    {
        $this->driver->save('post', 'v1.2', ['uuid' => 'v1.2']);

        $this->assertSame(['uuid' => 'v1.2'], $this->driver->findOne('post', 'v1.2'));
        $this->assertTrue($this->driver->exists('post', 'v1.2'));
    }
}
