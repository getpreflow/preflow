<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\Freshness;
use Preflow\Core\PageCache\TagIndex;

final class TagIndexTest extends TestCase
{
    private string $dir;

    private float $now = 1_800_000_000.0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tagindex_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function index(?bool $sqlite = null): TagIndex
    {
        return TagIndex::in($this->dir . '/rel1', fn (): float => $this->now, $sqlite);
    }

    private static function id(string $name): string
    {
        return sha1($name);
    }

    public function test_the_strictest_freshness_wins_per_page(): void
    {
        $index = $this->index();
        $index->record(self::id('a'), 'https://e.x/a', false, false, [
            'object:1' => [Freshness::Timed, 43200],
            'list:objects' => [Freshness::NextVisit, null],
            'menu' => [Freshness::Fresh, null],
        ]);
        $index->record(self::id('b'), 'https://e.x/b', false, false, [
            'object:1' => [Freshness::Timed, 600],
            'list:objects' => [Freshness::NextVisit],
        ]);
        $index->record(self::id('c'), 'https://e.x/c', true, true, ['object:1' => Freshness::Timed]);

        $this->assertSame(
            $this->sorted([self::id('a') => Freshness::Fresh, self::id('b') => Freshness::NextVisit, self::id('c') => Freshness::Timed]),
            $this->sorted($index->pagesFor(['object:1', 'list:objects', 'menu'])),
        );
        $this->assertSame($this->sorted([self::id('a') => Freshness::NextVisit, self::id('b') => Freshness::NextVisit]), $this->sorted($index->pagesFor(['list:objects'])));
        $this->assertSame([], $index->pagesFor(['unknown']));
        $this->assertSame([], $index->pagesFor([]));
    }

    public function test_recording_again_replaces_the_tags_and_the_index_persists(): void
    {
        $this->index()->record(self::id('a'), 'https://e.x/a', false, false, ['old' => [Freshness::Fresh, null]]);
        $this->index()->record(self::id('a'), 'https://e.x/a?x=1', true, true, ['new' => [Freshness::Fresh, null]]);

        $index = $this->index();
        $this->assertSame([], $index->pagesFor(['old']));
        $this->assertSame([self::id('a') => Freshness::Fresh], $index->pagesFor(['new']));
        $this->assertSame(
            ['url' => 'https://e.x/a?x=1', 'fragment' => true, 'hasQuery' => true, 'createdAt' => $this->now],
            $index->page(self::id('a')),
        );
        $pdo = $index->pdo();
        $this->assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        $this->assertSame(43200, $this->timed($index, 'x', 43200));
    }

    public function test_seconds_are_stored_only_for_timed_tags(): void
    {
        $index = $this->index();
        $index->record(self::id('a'), 'https://e.x/a', false, false, [
            'fresh' => [Freshness::Fresh, 60],
            'next' => [Freshness::NextVisit, 60],
            'timed' => [Freshness::Timed, 60],
        ]);

        $rows = $index->pdo()->query('SELECT tag, timed_seconds FROM tags ORDER BY tag')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(['fresh' => null, 'next' => null, 'timed' => 60], $rows);
    }

    public function test_an_everything_invalidation_counts_for_every_tag(): void
    {
        $index = $this->index();
        $index->recordInvalidation([TagIndex::EVERYTHING], $this->now);

        $this->assertTrue($index->invalidatedSince(['object:1'], $this->now - 1));
        $this->assertTrue($index->invalidatedSince([], $this->now - 1));
        $this->assertFalse($index->invalidatedSince(['object:1'], $this->now));
    }

    public function test_query_variants_per_path_are_capped_oldest_first(): void
    {
        $evicted = [];
        $index = new TagIndex($this->dir . '/rel1/' . TagIndex::FILE, fn (): float => $this->now, null, 0, 3,
            function (string $id) use (&$evicted): void { $evicted[] = $id; });
        $index->record(self::id('plain'), 'https://e.x/list', false, false, []);
        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->now += 1;
            $index->record(self::id('q' . $n), 'https://e.x/list?page=' . $n, false, true, []);
        }
        $index->record(self::id('other'), 'https://e.x/other?page=1', false, true, []);

        $this->assertSame([self::id('q1'), self::id('q2')], $evicted);
        $this->assertNull($index->page(self::id('q1')));
        $this->assertNotNull($index->page(self::id('q5')));
        $this->assertNotNull($index->page(self::id('plain')));
        $this->assertSame(5, $index->count());
    }

    public function test_evicted_files_are_forgotten_before_the_rows_are_committed(): void
    {
        $file = $this->dir . '/rel1/' . TagIndex::FILE;
        $seen = [];
        $index = new TagIndex($file, fn (): float => $this->now, null, 1, 0, function (string $id) use ($file, &$seen): void {
            // Another connection still sees the committed rows of the evicted page.
            $other = new \PDO('sqlite:' . $file);
            $seen[$id] = (int) $other->query("SELECT COUNT(*) FROM pages WHERE id = '{$id}'")->fetchColumn();
        });
        $index->record(self::id('a'), 'https://e.x/a', false, false, []);
        $this->now += 1;
        $index->record(self::id('b'), 'https://e.x/b', false, false, []);

        $this->assertSame([self::id('a') => 1], $seen);
        $this->assertNull($index->page(self::id('a')));
    }

    public function test_the_total_is_capped_and_evicted_files_are_forgotten(): void
    {
        $root = $this->dir . '/pages';
        $store = new \Preflow\Core\PageCache\PageStore($root, 'rel1', fn (): float => $this->now);
        $index = TagIndex::forStore($store, new \Preflow\Core\PageCache\PageCacheConfig(hosts: ['e.x'], maxPages: 2), fn (): float => $this->now);
        $keys = [];
        foreach (['a', 'b', 'c'] as $name) {
            $this->now += 1;
            $key = \Preflow\Core\PageCache\PageKey::fromRequest(new \Nyholm\Psr7\ServerRequest('GET', 'https://e.x/' . $name), new \Preflow\Core\PageCache\PageCacheConfig(hosts: ['e.x']));
            $evicted = $index->record($key->id(), $key->url(), false, false, ['news' => [Freshness::Fresh, null]]);
            $store->put($key, new \Preflow\Core\PageCache\StoredPage($name, [], $this->now));
            $keys[$name] = $key;
        }

        $this->assertSame([$keys['a']->id()], $evicted);
        $this->assertSame(2, $index->count());
        $this->assertNull($store->get($keys['a']));
        $this->assertNotNull($store->get($keys['c']));
        $this->assertSame(
            $this->sorted([$keys['b']->id() => Freshness::Fresh, $keys['c']->id() => Freshness::Fresh]),
            $this->sorted($index->pagesFor(['news'])),
        );
    }

    public function test_a_new_index_empties_the_pages_of_its_release(): void
    {
        $root = $this->dir . '/pages';
        $store = new \Preflow\Core\PageCache\PageStore($root, 'rel1', fn (): float => $this->now);
        $key = \Preflow\Core\PageCache\PageKey::fromRequest(new \Nyholm\Psr7\ServerRequest('GET', 'https://e.x/a'), new \Preflow\Core\PageCache\PageCacheConfig(hosts: ['e.x']));
        $store->put($key, new \Preflow\Core\PageCache\StoredPage('untracked', [], 1.0));

        $this->assertTrue(TagIndex::in($store->releaseDir())->available());

        $this->assertNull($store->get($key));
    }

    public function test_a_corrupt_index_is_set_aside_and_recreated(): void
    {
        mkdir($this->dir . '/rel1');
        file_put_contents($this->dir . '/rel1/' . TagIndex::FILE, str_repeat('not a database ', 400));
        $log = ini_set('error_log', $this->dir . '/php.log');

        try {
            $index = $this->index();
            $this->assertTrue($index->available());
            $index->record(self::id('a'), 'https://e.x/a', false, false, ['news' => [Freshness::Fresh, null]]);
            $this->assertSame([self::id('a') => Freshness::Fresh], $index->pagesFor(['news']));
        } finally {
            ini_set('error_log', (string) $log);
        }
        $this->assertCount(1, glob($this->dir . '/rel1/' . TagIndex::FILE . '.corrupt-*'));
        $this->assertStringContainsString('was corrupt', (string) file_get_contents($this->dir . '/php.log'));
    }

    public function test_an_index_of_an_older_schema_is_recreated(): void
    {
        mkdir($this->dir . '/rel1');
        $old = new \PDO('sqlite:' . $this->dir . '/rel1/' . TagIndex::FILE);
        $old->exec('CREATE TABLE pages (id TEXT PRIMARY KEY, url TEXT NOT NULL, fragment INTEGER NOT NULL, has_query INTEGER NOT NULL, created_at REAL NOT NULL)');
        $old->exec("INSERT INTO pages VALUES ('x', 'https://e.x/', 0, 0, 1.0)");
        $old->exec('CREATE TABLE refresh_queue (id INTEGER)');
        $old = null;

        $index = $this->index();
        $index->record(self::id('a'), 'https://e.x/a?q=1', false, true, []);

        $this->assertSame(1, $index->count());
        $this->assertSame(TagIndex::SCHEMA, (int) $index->pdo()->query('PRAGMA user_version')->fetchColumn());
        // Tables of others in the same file stay.
        $this->assertSame('refresh_queue', $index->pdo()->query("SELECT name FROM sqlite_master WHERE name = 'refresh_queue'")->fetchColumn());
    }

    public function test_invalidations_keep_microseconds(): void
    {
        $this->now = 1794162049.083123;
        $index = $this->index();
        $index->recordInvalidation(['t'], 1794162049.083123);

        $this->assertTrue($index->invalidatedSince(['t'], 1794162049.083122), 'one microsecond after the render started');
        $this->assertFalse($index->invalidatedSince(['t'], 1794162049.083123));
    }

    public function test_invalidated_since_sees_only_later_invalidations(): void
    {
        $index = $this->index();
        $at = $this->now - 10;
        $index->recordInvalidation(['object:1', 'list:objects'], $at);

        $this->assertTrue($index->invalidatedSince(['object:1'], $at - 0.5));
        $this->assertTrue($index->invalidatedSince(['menu', 'list:objects'], $at - 0.5));
        $this->assertFalse($index->invalidatedSince(['object:1'], $at));
        $this->assertFalse($index->invalidatedSince(['object:1'], $at + 0.5));
        $this->assertFalse($index->invalidatedSince(['object:2'], 0.0));
        $this->assertFalse($index->invalidatedSince([], 0.0));
    }

    public function test_invalidations_are_pruned_after_an_hour(): void
    {
        $index = $this->index();
        $index->recordInvalidation(['object:1'], $this->now - 3700);
        $this->now += 10;
        $index->recordInvalidation(['object:2'], $this->now);

        $this->assertFalse($index->invalidatedSince(['object:1'], 0.0));
        $this->assertTrue($index->invalidatedSince(['object:2'], 0.0));
    }

    public function test_forget_removes_the_page_and_its_tags(): void
    {
        $index = $this->index();
        $index->record(self::id('a'), 'https://e.x/a', false, false, ['news' => [Freshness::Fresh, null], 'menu' => [Freshness::Fresh, null]]);
        $index->record(self::id('b'), 'https://e.x/b', false, false, ['news' => [Freshness::Fresh, null]]);

        $index->forget(self::id('a'));

        $this->assertSame([self::id('b') => Freshness::Fresh], $index->pagesFor(['news', 'menu']));
        $this->assertNull($index->page(self::id('a')));
        $this->assertSame(0, (int) $index->pdo()->query("SELECT COUNT(*) FROM tags WHERE page_id = '" . self::id('a') . "'")->fetchColumn());
    }

    public function test_clear_empties_the_index_and_counts_pages(): void
    {
        $index = $this->index();
        $index->record(self::id('a'), 'https://e.x/a', false, false, ['news' => [Freshness::Fresh, null]]);
        $index->record(self::id('b'), 'https://e.x/b', false, false, ['news' => [Freshness::Fresh, null]]);
        $index->recordInvalidation(['news'], $this->now);

        $this->assertSame(2, $index->clear());
        $this->assertSame([], $index->pagesFor(['news']));
        $this->assertFalse($index->invalidatedSince(['news'], 0.0));
    }

    public function test_many_tags_are_queried_in_chunks(): void
    {
        $index = $this->index();
        $tags = [];
        for ($i = 0; $i < 1200; $i++) {
            $tags['object:' . $i] = [Freshness::Fresh, null];
        }
        $index->record(self::id('a'), 'https://e.x/a', false, false, $tags);

        $this->assertSame([self::id('a') => Freshness::Fresh], $index->pagesFor(array_keys($tags)));
        $index->recordInvalidation(['object:1199'], $this->now);
        $this->assertTrue($index->invalidatedSince(array_keys($tags), 0.0));
    }

    public function test_without_pdo_sqlite_the_index_is_unavailable_and_never_throws(): void
    {
        $index = $this->index(sqlite: false);

        $this->assertFalse($index->available());
        $this->assertNull($index->pdo());
        $index->record(self::id('a'), 'https://e.x/a', false, false, ['news' => [Freshness::Fresh, null]]);
        $index->recordInvalidation(['news'], 1.0);
        $index->forget(self::id('a'));
        $this->assertSame([], $index->pagesFor(['news']));
        $this->assertNull($index->page(self::id('a')));
        // Nothing may be stored that could be stale.
        $this->assertTrue($index->invalidatedSince(['news'], 0.0));
        $this->assertSame(0, $index->clear());
        $this->assertFileDoesNotExist($this->dir . '/rel1/' . TagIndex::FILE);
    }

    public function test_an_index_that_cannot_be_opened_is_unavailable(): void
    {
        file_put_contents($this->dir . '/blocker', 'a file where the folder should be');
        $index = TagIndex::in($this->dir . '/blocker/rel1');

        $this->assertFalse($index->available());
        $this->assertTrue($index->invalidatedSince(['news'], 0.0));
    }

    /**
     * @param array<string, Freshness> $pages
     * @return array<string, Freshness>
     */
    private function sorted(array $pages): array
    {
        ksort($pages);

        return $pages;
    }

    private function timed(TagIndex $index, string $tag, int $seconds): int
    {
        $index->record(self::id('t'), 'https://e.x/t', false, false, [$tag => [Freshness::Timed, $seconds]]);

        return (int) $index->pdo()->query("SELECT timed_seconds FROM tags WHERE tag = '{$tag}'")->fetchColumn();
    }
}
