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
