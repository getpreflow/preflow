<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Redirects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Redirects\NotFoundEntries;
use Preflow\Folio\Tests\Support\InternalStorage;

/** Folio's not-found list (folio_not_found) on JSON and on SQLite. */
final class NotFoundEntriesTest extends TestCase
{
    use InternalStorage;

    private DataManager $dm;

    protected function tearDown(): void
    {
        $this->removeInternalStorage();
    }

    private function log(string $storage, int $limit = 1000, int $checkEvery = 1): NotFoundEntries
    {
        $this->dm = $this->internalStorage($storage, NotFoundEntries::definition());

        return new NotFoundEntries($this->dm, $limit, $checkEvery);
    }

    private static function at(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when);
    }

    /** @return list<string> "locale path" of the visible rows, in order */
    private static function addresses(NotFoundEntries $log): array
    {
        return array_map(static fn (array $row): string => $row['locale'] . ' ' . $row['path'], $log->visible());
    }

    #[DataProvider('storages')]
    public function test_first_hit_then_count(string $storage): void
    {
        $log = $this->log($storage);
        $log->record('/missing', 'de', self::at('2026-11-01T10:00:00+00:00'));

        $id = NotFoundEntries::id('de', '/missing');
        $row = $log->find($id);
        $this->assertSame(1, $row['hits']);
        $this->assertSame('/missing', $row['path']);
        $this->assertSame('de', $row['locale']);
        $this->assertSame('2026-11-01T10:00:00+00:00', $row['first_hit_at']);
        $this->assertSame('2026-11-01T10:00:00+00:00', $row['last_hit_at']);
        $this->assertNull($row['dismissed_at']);

        $log->record('/missing', 'de', self::at('2026-11-02T12:00:00+00:00'));
        $log->record('/missing', 'en', self::at('2026-11-02T13:00:00+00:00'));

        $row = $log->find($id);
        $this->assertSame(2, $row['hits']);
        $this->assertSame('2026-11-01T10:00:00+00:00', $row['first_hit_at']);
        $this->assertSame('2026-11-02T12:00:00+00:00', $row['last_hit_at']);
        $this->assertSame(1, $log->find(NotFoundEntries::id('en', '/missing'))['hits'], 'another language is another row');

        $log->forget('de', '/missing');
        $this->assertNull($log->find($id));
        $this->assertNotNull($log->find(NotFoundEntries::id('en', '/missing')));
    }

    #[DataProvider('storages')]
    public function test_language_neutral_forget_drops_the_path_in_every_locale(string $storage): void
    {
        // A site without locale prefixes keeps its addresses under '' but records visits under
        // the locale each request had.
        $log = $this->log($storage);
        $at = self::at('2026-11-01T10:00:00+00:00');
        $log->record('/old', 'de', $at);
        $log->record('/old', 'en', $at);
        $log->record('/older', 'en', $at);
        $log->record('/OLD', 'en', $at);

        $log->forget('', '/old/');

        $this->assertNull($log->find(NotFoundEntries::id('de', '/old')));
        $this->assertNull($log->find(NotFoundEntries::id('en', '/old')));
        $this->assertNotNull($log->find(NotFoundEntries::id('en', '/older')));
        $this->assertNotNull($log->find(NotFoundEntries::id('en', '/OLD')), 'exact paths only');
    }

    #[DataProvider('storages')]
    public function test_forget_under_drops_the_area_and_what_is_below(string $storage): void
    {
        $log = $this->log($storage);
        $at = self::at('2026-11-01T10:00:00+00:00');
        foreach ([['de', '/blog'], ['de', '/blog/a/b'], ['en', '/blog/a'], ['de', '/blogger'], ['de', '/BLOG/a']] as [$locale, $path]) {
            $log->record($path, $locale, $at);
        }

        $log->forgetUnder('de', '/blog/');

        $this->assertNull($log->find(NotFoundEntries::id('de', '/blog')));
        $this->assertNull($log->find(NotFoundEntries::id('de', '/blog/a/b')));
        $this->assertNotNull($log->find(NotFoundEntries::id('en', '/blog/a')), 'another language');
        $this->assertNotNull($log->find(NotFoundEntries::id('de', '/blogger')), 'whole segments only');
        $this->assertNotNull($log->find(NotFoundEntries::id('de', '/BLOG/a')), 'exact paths only');

        $log->forgetUnder('', '/blog');
        $this->assertNull($log->find(NotFoundEntries::id('en', '/blog/a')), 'language-neutral: every locale');
    }

    #[DataProvider('storages')]
    public function test_single_hits_are_hidden(string $storage): void
    {
        $log = $this->log($storage);
        $log->record('/once', 'en', self::at('2026-11-03T10:00:00+00:00'));
        foreach (['01', '02', '03'] as $day) {
            $log->record('/often', 'en', self::at("2026-11-{$day}T09:00:00+00:00"));
        }
        $log->record('/twice-old', 'en', self::at('2026-10-01T09:00:00+00:00'));
        $log->record('/twice-old', 'en', self::at('2026-10-02T09:00:00+00:00'));
        $log->record('/twice-new', 'en', self::at('2026-11-01T09:00:00+00:00'));
        $log->record('/twice-new', 'en', self::at('2026-11-02T09:00:00+00:00'));

        // Most hits first, then the most recent.
        $this->assertSame(['en /often', 'en /twice-new', 'en /twice-old'], self::addresses($log));
    }

    #[DataProvider('storages')]
    public function test_limit_drops_least_recent(string $storage): void
    {
        $log = $this->log($storage, 3);
        $log->record('/a', 'en', self::at('2026-11-01T09:00:00+00:00'));
        $log->record('/b', 'en', self::at('2026-11-02T09:00:00+00:00'));
        $log->record('/c', 'en', self::at('2026-11-03T09:00:00+00:00'));
        // /a is hit again: now /b is the least recent.
        $log->record('/a', 'en', self::at('2026-11-04T09:00:00+00:00'));
        $log->record('/d', 'en', self::at('2026-11-05T09:00:00+00:00'));

        $this->assertNull($log->find(NotFoundEntries::id('en', '/b')));
        foreach (['/a', '/c', '/d'] as $path) {
            $this->assertNotNull($log->find(NotFoundEntries::id('en', $path)), $path);
        }
    }

    #[DataProvider('storages')]
    public function test_limit_is_checked_now_and_then_and_holds(string $storage): void
    {
        $log = $this->log($storage, 10, 5);
        $start = self::at('2026-11-01T00:00:00+00:00');
        for ($i = 0; $i < 120; $i++) {
            $log->record('/scan-' . $i, 'en', $start->modify("+{$i} minutes"));
        }

        $rows = $this->dm->queryIn(NotFoundEntries::definition())->get()->items();
        // Checked on about one new address in five: never far past the limit, never under it.
        $this->assertGreaterThanOrEqual(10, count($rows));
        $this->assertLessThanOrEqual(10 + 4 * 5, count($rows));
        $this->assertNull($log->find(NotFoundEntries::id('en', '/scan-0')), 'the least recent went first');
        $kept = array_map(static fn ($row): string => (string) $row->get('path'), $rows);
        sort($kept, SORT_NATURAL);
        $this->assertSame('/scan-119', end($kept), 'the most recent stays');
        $this->assertSame(range(120 - count($rows), 119), array_map(static fn (string $p): int => (int) substr($p, 6), $kept), 'only the most recent are kept');
    }

    #[DataProvider('storages')]
    public function test_stamps_are_kept_in_utc(string $storage): void
    {
        $log = $this->log($storage);
        $log->record('/zone', 'en', self::at('2026-11-01T12:00:00+02:00'));

        $this->assertSame('2026-11-01T10:00:00+00:00', $log->find(NotFoundEntries::id('en', '/zone'))['last_hit_at']);
    }

    #[DataProvider('storages')]
    public function test_dismissed_stays_hidden_within_30_days_and_returns_after(string $storage): void
    {
        $log = $this->log($storage);
        $log->record('/gone', 'en', self::at('2026-10-01T09:00:00+00:00'));
        $log->record('/gone', 'en', self::at('2026-10-02T09:00:00+00:00'));
        $id = NotFoundEntries::id('en', '/gone');
        $this->assertSame(['en /gone'], self::addresses($log));

        $log->dismiss($id, self::at('2026-10-03T09:00:00+00:00'));
        $this->assertSame([], self::addresses($log));

        // Within 30 days: only the last hit moves.
        $log->record('/gone', 'en', self::at('2026-10-20T09:00:00+00:00'));
        $row = $log->find($id);
        $this->assertSame(2, $row['hits']);
        $this->assertSame('2026-10-20T09:00:00+00:00', $row['last_hit_at']);
        $this->assertSame('2026-10-03T09:00:00+00:00', $row['dismissed_at']);
        $this->assertSame([], self::addresses($log));

        // 30 days after the dismissal: counted afresh.
        $log->record('/gone', 'en', self::at('2026-11-02T09:00:00+00:00'));
        $row = $log->find($id);
        $this->assertSame(1, $row['hits']);
        $this->assertSame('2026-11-02T09:00:00+00:00', $row['first_hit_at']);
        $this->assertNull($row['dismissed_at']);
        $this->assertSame([], self::addresses($log), 'one hit since: still hidden');

        $log->record('/gone', 'en', self::at('2026-11-03T09:00:00+00:00'));
        $this->assertSame(['en /gone'], self::addresses($log));
    }

    #[DataProvider('storages')]
    public function test_rows_hold_no_visitor_data(string $storage): void
    {
        $log = $this->log($storage);
        $log->record('/missing', 'en', self::at('2026-11-01T10:00:00+00:00'));

        $columns = ['id', 'path', 'locale', 'hits', 'first_hit_at', 'last_hit_at', 'dismissed_at'];
        sort($columns);
        // The row as the storage holds it, not as a record built from the definition.
        $stored = $this->dm->driver('default')->findOne(NotFoundEntries::TABLE, NotFoundEntries::id('en', '/missing'), 'id') ?? [];
        $keys = array_keys($stored);
        sort($keys);
        $this->assertSame($columns, $keys);

        $definition = array_merge(['id'], array_keys(NotFoundEntries::definition()->fields));
        sort($definition);
        $this->assertSame($columns, $definition);
    }

    #[DataProvider('storages')]
    public function test_a_known_path_is_written_at_most_once_a_minute(string $storage): void
    {
        $log = $this->log($storage);
        $id = NotFoundEntries::id('de', '/missing');
        $log->record('/missing', 'de', self::at('2026-11-01T10:00:00+00:00'));
        $log->record('/missing', 'de', self::at('2026-11-01T10:00:59+00:00'));
        $this->assertSame(1, $log->find($id)['hits']);
        $this->assertSame('2026-11-01T10:00:00+00:00', $log->find($id)['last_hit_at']);

        $log->record('/missing', 'de', self::at('2026-11-01T10:01:00+00:00'));
        $this->assertSame(2, $log->find($id)['hits']);
        $this->assertSame('2026-11-01T10:01:00+00:00', $log->find($id)['last_hit_at']);
    }

    #[DataProvider('storages')]
    public function test_new_paths_stop_at_the_cap(string $storage): void
    {
        $log = $this->log($storage, 5000);
        $this->fill(NotFoundEntries::MAX_PATHS);

        $log->record('/new', 'de', self::at('2026-11-02T10:00:00+00:00'));
        $this->assertNull($log->find(NotFoundEntries::id('de', '/new')));

        $log->record('/p0', 'de', self::at('2026-11-02T10:00:00+00:00'));
        $this->assertSame(2, $log->find(NotFoundEntries::id('de', '/p0'))['hits']);
    }

    #[DataProvider('storages')]
    public function test_a_list_over_the_cap_is_left_as_it_is(string $storage): void
    {
        $log = $this->log($storage, 5000, 1);
        $this->fill(NotFoundEntries::MAX_PATHS + 5);

        $log->record('/new', 'de', self::at('2026-11-02T10:00:00+00:00'));
        $log->record('/p1', 'de', self::at('2026-11-02T10:00:00+00:00'));

        $counted = $this->dm->queryIn(NotFoundEntries::definition())->aggregate()->count()->get();
        $this->assertSame(NotFoundEntries::MAX_PATHS + 5, (int) $counted[0]['count']);
        $this->assertSame(2, $log->find(NotFoundEntries::id('de', '/p1'))['hits']);
    }

    private function fill(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->dm->saveType(\Preflow\Data\DynamicRecord::fromArray(NotFoundEntries::definition(), [
                'id' => NotFoundEntries::id('de', "/p{$i}"),
                'path' => "/p{$i}",
                'locale' => 'de',
                'hits' => 1,
                'first_hit_at' => '2026-11-01T10:00:00+00:00',
                'last_hit_at' => '2026-11-01T10:00:00+00:00',
                'dismissed_at' => null,
            ]), false);
        }
    }
}
