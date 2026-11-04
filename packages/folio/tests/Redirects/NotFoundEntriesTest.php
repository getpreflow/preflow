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

    private function log(string $storage, int $limit = 1000): NotFoundEntries
    {
        $this->dm = $this->internalStorage($storage, NotFoundEntries::definition());

        return new NotFoundEntries($this->dm, $limit);
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
}
