<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Activity;

use PHPUnit\Framework\Attributes\DataProvider;
use Preflow\Core\Activity\Activity;
use Preflow\Core\Activity\ActivityKind;
use Preflow\Data\Activity\StoredActivityLog;
use Preflow\Data\DataManager;
use Preflow\Data\Schema\RecordTableSync;

/**
 * StoredActivityLog on one storage: the using class gives the DataManager (storages()) and
 * cleans up. Every scenario runs on JSON, SQLite and (test:mysql) MySQL alike.
 */
trait ActivityLogScenarios
{
    private \DateTimeImmutable $now;

    private int $prunes = 0;

    private bool $pruneNext = false;

    private ?DataManager $dm = null;

    abstract protected function dataManager(string $storage): DataManager;

    private function log(string $storage, int $keepDays = 90, int $keepRows = 10000): StoredActivityLog
    {
        $this->now = new \DateTimeImmutable('2026-11-15T10:00:00.000000+00:00');
        $dm = $this->dm = $this->dataManager($storage);
        $log = new StoredActivityLog(
            $dm,
            $storage,
            $keepDays,
            $keepRows,
            fn (): \DateTimeImmutable => $this->now,
            function (): bool {
                $this->prunes++;
                $prune = $this->pruneNext;
                $this->pruneNext = false;

                return $prune;
            },
        );
        (new RecordTableSync($dm))->syncAll($log->tables());

        return $log;
    }

    private function tick(string $modify = '+1 second'): void
    {
        $this->now = $this->now->modify($modify);
    }

    private static function saved(string $id, string $by = 'u1', array $changed = ['title'], string $subject = 'page'): Activity
    {
        return new Activity($subject, $id, 'Title ' . $id, ActivityKind::Saved, $changed, $by);
    }

    /** @param list<Activity> $items @return list<string> */
    private static function ids(array $items): array
    {
        return array_map(static fn (Activity $a): string => $a->id, $items);
    }

    #[DataProvider('storages')]
    public function test_newest_first_with_the_time_set(string $storage): void
    {
        $log = $this->log($storage);
        $log->record(new Activity('page', 'p1', 'Über uns', ActivityKind::Created, ['title', 'body'], 'u1'));
        $this->tick();
        $log->record(new Activity('post', 'b1', 'Hello', ActivityKind::Published, by: 'u2'));

        $page = $log->recent(10);
        $this->assertSame(['b1', 'p1'], self::ids($page->items));
        $this->assertNull($page->next);
        $first = $page->items[1];
        $this->assertSame('page', $first->subject);
        $this->assertSame('Über uns', $first->title);
        $this->assertSame(ActivityKind::Created, $first->kind);
        $this->assertSame(['title', 'body'], $first->changed);
        $this->assertSame('u1', $first->by);
        $this->assertSame('2026-11-15T10:00:00+00:00', $first->at);
        $this->assertSame('u2', $page->items[0]->by);
    }

    #[DataProvider('storages')]
    public function test_a_given_time_is_kept_in_utc(string $storage): void
    {
        $log = $this->log($storage);
        $log->record(new Activity('page', 'p1', 'Home', ActivityKind::Saved, at: '2026-11-15T09:30:00+01:00'));

        $this->assertSame('2026-11-15T08:30:00+00:00', $log->recent(1)->items[0]->at);
    }

    #[DataProvider('storages')]
    public function test_cursor_pages_through_everything_once(string $storage): void
    {
        $log = $this->log($storage);
        foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
            $log->record(new Activity('page', $id, $id, ActivityKind::Created));
            $this->tick();
        }

        $one = $log->recent(2);
        $this->assertSame(['e', 'd'], self::ids($one->items));
        $this->assertNotNull($one->next);
        $two = $log->recent(2, null, $one->next);
        $this->assertSame(['c', 'b'], self::ids($two->items));
        $three = $log->recent(2, null, $two->next);
        $this->assertSame(['a'], self::ids($three->items));
        $this->assertNull($three->next);
    }

    #[DataProvider('storages')]
    public function test_cursor_is_stable_under_concurrent_inserts(string $storage): void
    {
        $log = $this->log($storage);
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $log->record(new Activity('page', $id, $id, ActivityKind::Created));
        }
        // All four in the same second (and microsecond): the order is still total and fixed.
        $one = $log->recent(2);
        $this->assertSame(self::ids($one->items), self::ids($log->recent(2)->items));

        $this->tick();
        $log->record(new Activity('page', 'new', 'new', ActivityKind::Created));
        $this->tick();
        $log->record(new Activity('page', 'newer', 'newer', ActivityKind::Created));

        $two = $log->recent(2, null, $one->next);
        $seen = [...self::ids($one->items), ...self::ids($two->items)];
        $this->assertCount(4, array_unique($seen));
        $this->assertEqualsCanonicalizing(['a', 'b', 'c', 'd'], $seen);
        $this->assertNull($two->next);
        $this->assertSame(['newer', 'new'], array_slice(self::ids($log->recent(10)->items), 0, 2));
    }

    #[DataProvider('storages')]
    public function test_an_unreadable_cursor_reads_from_the_start(string $storage): void
    {
        $log = $this->log($storage);
        $log->record(new Activity('page', 'a', 'a', ActivityKind::Created));

        $this->assertSame(['a'], self::ids($log->recent(5, null, "x' OR 1=1 --")->items));
        $this->assertSame([], $log->recent(0)->items);
    }

    #[DataProvider('storages')]
    public function test_subjects_filter(string $storage): void
    {
        $log = $this->log($storage);
        $log->record(self::saved('p1'));
        $log->record(self::saved('b1', subject: 'post'));
        $log->record(new Activity('asset', 'f1', 'photo.jpg', ActivityKind::Uploaded));

        $this->assertSame(['p1'], self::ids($log->recent(10, ['page'])->items));
        $this->assertEqualsCanonicalizing(['b1', 'f1'], self::ids($log->recent(10, ['post', 'asset'])->items));
        $this->assertSame([], $log->recent(10, [])->items);
    }

    #[DataProvider('storages')]
    public function test_saves_by_the_same_person_coalesce_within_ten_minutes(string $storage): void
    {
        $log = $this->log($storage);
        $log->record(self::saved('p1', changed: ['title']));
        $this->tick('+9 minutes');
        $log->record(new Activity('page', 'p1', 'Renamed', ActivityKind::Saved, ['body', 'title'], 'u1'));

        $items = $log->recent(10)->items;
        $this->assertCount(1, $items);
        $this->assertSame(['title', 'body'], $items[0]->changed);
        $this->assertSame('Renamed', $items[0]->title);
        $this->assertSame('2026-11-15T10:09:00+00:00', $items[0]->at);

        // The window runs from the last save: still the same row.
        $this->tick('+9 minutes');
        $log->record(self::saved('p1', changed: ['teaser']));
        $this->assertCount(1, $log->recent(10)->items);
    }

    #[DataProvider('storages')]
    public function test_saves_do_not_coalesce_across_people_time_or_other_events(string $storage): void
    {
        $log = $this->log($storage);
        $log->record(self::saved('p1', 'u1'));
        $this->tick();
        $log->record(self::saved('p1', 'u2'));
        $this->assertCount(2, $log->recent(10)->items, 'another person');

        $this->tick('+11 minutes');
        $log->record(self::saved('p1', 'u2'));
        $this->assertCount(3, $log->recent(10)->items, 'after ten minutes');

        $this->tick();
        $log->record(new Activity('page', 'p1', 'p1', ActivityKind::Published, by: 'u2'));
        $this->tick();
        $log->record(self::saved('p1', 'u2'));
        $this->assertSame(
            ['saved', 'published', 'saved', 'saved', 'saved'],
            array_map(static fn (Activity $a): string => $a->kind->value, $log->recent(10)->items),
            'a publish in between starts a new row',
        );

        $this->tick();
        $log->record(new Activity('page', 'p2', 'p2', ActivityKind::Created, by: 'u2'));
        $this->tick();
        $log->record(new Activity('page', 'p2', 'p2', ActivityKind::Created, by: 'u2'));
        $this->assertCount(7, $log->recent(10)->items, 'only saves coalesce');
    }

    #[DataProvider('storages')]
    public function test_a_save_whose_row_was_pruned_starts_a_new_one(string $storage): void
    {
        $log = $this->log($storage);
        $log->record(self::saved('p1', changed: ['title']));
        $row = $log->recent(1)->items[0];
        $this->assertNotNull($this->dm);
        foreach ($this->dm->queryIn($log->definition())->get()->items() as $stored) {
            $this->dm->deleteIn($log->definition(), (string) $stored->getId());
        }
        $this->tick();
        $log->record(self::saved('p1', changed: ['body']));

        $items = $log->recent(10)->items;
        $this->assertCount(1, $items);
        $this->assertSame(['body'], $items[0]->changed);
        $this->assertSame($row->id, $items[0]->id);
    }

    #[DataProvider('storages')]
    public function test_a_coalesced_row_moves_to_the_top(string $storage): void
    {
        $log = $this->log($storage);
        $log->record(self::saved('p1'));
        $this->tick();
        $log->record(self::saved('p2'));
        $this->tick();
        $log->record(self::saved('p1', changed: ['body']));

        $this->assertSame(['p1', 'p2'], self::ids($log->recent(10)->items));
    }

    #[DataProvider('storages')]
    public function test_pruning_keeps_the_days_and_rows(string $storage): void
    {
        $log = $this->log($storage, keepDays: 10, keepRows: 3);
        foreach (['old1', 'old2'] as $id) {
            $log->record(new Activity('page', $id, $id, ActivityKind::Created));
            $this->tick();
        }
        $this->tick('+11 days');
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $log->record(new Activity('page', $id, $id, ActivityKind::Created));
            $this->tick();
        }
        $this->assertCount(6, $log->recent(50)->items, 'nothing pruned yet');

        $this->pruneNext = true;
        $log->record(new Activity('page', 'e', 'e', ActivityKind::Created));

        $this->assertSame(['e', 'd', 'c'], self::ids($log->recent(50)->items));
    }

    #[DataProvider('storages')]
    public function test_pruning_runs_on_writes_never_on_reads(string $storage): void
    {
        $log = $this->log($storage);
        $log->record(self::saved('p1'));
        $this->assertSame(1, $this->prunes);

        $log->recent(10);
        $log->recent(10, ['page'], null);
        $this->assertSame(1, $this->prunes);
    }

    #[DataProvider('storages')]
    public function test_zero_keeps_everything(string $storage): void
    {
        $log = $this->log($storage, keepDays: 0, keepRows: 0);
        $log->record(new Activity('page', 'a', 'a', ActivityKind::Created));
        $this->tick('+400 days');
        $this->pruneNext = true;
        $log->record(new Activity('page', 'b', 'b', ActivityKind::Created));

        $this->assertSame(['b', 'a'], self::ids($log->recent(10)->items));
    }

    #[DataProvider('storages')]
    public function test_a_long_title_and_many_fields_are_kept(string $storage): void
    {
        $log = $this->log($storage);
        $title = str_repeat('Ä', 600);
        $changed = array_map(static fn (int $i): string => 'field_' . $i, range(1, 80));
        $log->record(new Activity('page', 'p1', $title, ActivityKind::Saved, $changed, 'someone@example.org'));

        $item = $log->recent(1)->items[0];
        $this->assertSame($title, $item->title);
        $this->assertSame($changed, $item->changed);
        $this->assertSame('someone@example.org', $item->by);
    }
}
