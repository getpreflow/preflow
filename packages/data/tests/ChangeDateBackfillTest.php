<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\FrozenClock;
use Preflow\Data\ChangeDateBackfill;
use Preflow\Data\ChangeDateSource;
use Preflow\Data\ChangeDates;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Testing\ContextFactory;

/**
 * ChangeDateBackfill (`preflow migrate`'s data.change-dates, 0.84.0) beyond the storage contract:
 * a JSON file's time, a published copy's time, a type's own updated field, and a SQL table its sync
 * has not given the column yet.
 */
final class ChangeDateBackfillTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_backfill_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    /**
     * @param list<TypeDefinition> $defs
     * @param list<ChangeDateSource> $sources
     */
    private static function backfill(DataManager $dm, array $defs, array $sources = []): array
    {
        return Contexts::run(
            ContextFactory::forTest()->withClock(new FrozenClock(new \DateTimeImmutable('2026-11-22T12:00:00Z'))),
            static fn (): array => (new ChangeDateBackfill($dm, static fn (): array => $defs, sources: $sources))->run(),
        );
    }

    /** A source that reads the row's "seen" key, or throws $failure. */
    private static function source(?string $failure = null): ChangeDateSource
    {
        return new class ($failure) implements ChangeDateSource {
            public function __construct(private readonly ?string $failure) {}

            public function label(): string
            {
                return 'the seen time';
            }

            public function changedAt(TypeDefinition $def, array $rows): array
            {
                if ($this->failure !== null) {
                    throw new \RuntimeException($this->failure);
                }
                $times = [];
                foreach ($rows as $row) {
                    $at = ChangeDates::parse($row['seen'] ?? null);
                    if ($at !== null) {
                        $times[(string) $row['uuid']] = $at;
                    }
                }

                return $times;
            }
        };
    }

    public function test_a_backfilled_json_file_is_the_one_a_save_writes(): void
    {
        $driver = new JsonFileDriver($this->dir . '/data');
        $dm = new DataManager(['default' => $driver], 'default');
        $def = new TypeDefinition('page', 'page', 'default', ['title' => new TypeFieldDefinition('title')]);
        $driver->save('page', 'a', ['title' => 'A', 'uuid' => 'a', '_version' => 2, '_order' => 3]);
        $file = $this->dir . '/data/page/a.json';

        self::backfill($dm, [$def]);
        $backfilled = (string) file_get_contents($file);
        $this->assertSame(['title', 'uuid', '_version', '_order', ChangeDates::FIELD], array_keys(json_decode($backfilled, true)), 'the change date last among the system keys');

        Contexts::run(ContextFactory::forTest()->withClock(new FrozenClock(new \DateTimeImmutable('2026-12-01T00:00:00Z'))), static fn () => $dm->saveType($dm->findIn($def, 'a'), false));
        $this->assertSame($backfilled, (string) file_get_contents($file), 'a save that changes nothing leaves the bytes');

        // The same record written by a save from the start: the same layout.
        $dm->driver('default')->delete('page', 'a');
        Contexts::run(ContextFactory::forTest()->withClock(new FrozenClock(new \DateTimeImmutable((string) json_decode($backfilled, true)[ChangeDates::FIELD]))), static fn () => $dm->saveType(
            \Preflow\Data\DynamicRecord::fromArray($def, ['title' => 'A', 'uuid' => 'a', '_version' => 2, '_order' => 3]),
            false,
        ));
        $this->assertSame($backfilled, (string) file_get_contents($file), 'one record, one byte layout, whoever wrote it');
    }

    public function test_a_json_record_gets_its_files_time_and_a_registered_source_comes_before_it(): void
    {
        $driver = new JsonFileDriver($this->dir . '/data');
        $dm = new DataManager(['default' => $driver], 'default');
        $def = new TypeDefinition('page', 'page', 'default', ['title' => new TypeFieldDefinition('title')]);
        $driver->save('page', 'a', ['uuid' => 'a', 'title' => 'A', '_published_at' => '2025-05-05T07:05:05+02:00']);
        touch($this->dir . '/data/page/a.json', (new \DateTimeImmutable('2024-04-04T04:04:04Z'))->getTimestamp());
        $driver->save('page', 'b', ['uuid' => 'b', 'title' => 'B', 'seen' => '2025-06-06T06:06:06Z']);

        $lines = self::backfill($dm, [$def], [self::source()]);

        $this->assertSame(['gave 2 records of page a change date (1 from the seen time, 1 from the file time)'], $lines);
        $this->assertSame('2024-04-04T04:04:04+00:00', $driver->findOne('page', 'a')[ChangeDates::FIELD], 'the data layer reads no publishing key itself');
        $this->assertSame('2025-06-06T06:06:06+00:00', $driver->findOne('page', 'b')[ChangeDates::FIELD]);
    }

    public function test_a_source_that_cannot_answer_leaves_the_table_for_the_next_run(): void
    {
        $driver = new JsonFileDriver($this->dir . '/data');
        $dm = new DataManager(['default' => $driver], 'default');
        $def = new TypeDefinition('page', 'page', 'default', ['title' => new TypeFieldDefinition('title')]);
        $driver->save('page', 'a', ['uuid' => 'a', 'title' => 'A']);

        $lines = self::backfill($dm, [$def], [self::source('index offline')]);

        $this->assertSame(['refused: page: no change dates given (the seen time: index offline); nothing weaker decides, run migrate again'], $lines);
        $this->assertArrayNotHasKey(ChangeDates::FIELD, $driver->findOne('page', 'a'));
    }

    public function test_a_types_own_updated_field_is_read_never_written(): void
    {
        $driver = new JsonFileDriver($this->dir . '/data');
        $dm = new DataManager(['default' => $driver], 'default');
        $def = new TypeDefinition('memo', 'memo', 'default', ['title' => new TypeFieldDefinition('title'), 'updated_at' => new TypeFieldDefinition('updated_at', 'date')]);
        $driver->save('memo', 'kept', ['uuid' => 'kept', 'title' => 'K', 'updated_at' => '2026-01-02T03:04:05+01:00']);
        $driver->save('memo', 'empty', ['uuid' => 'empty', 'title' => 'E', 'updated_at' => '']);
        touch($this->dir . '/data/memo/empty.json', (new \DateTimeImmutable('2023-03-03T03:03:03Z'))->getTimestamp());

        $this->assertSame(['gave 2 records of memo a change date (1 from its updated time, 1 from the file time)'], self::backfill($dm, [$def]));

        $this->assertSame('2026-01-02T02:04:05+00:00', $driver->findOne('memo', 'kept')[ChangeDates::FIELD], 'its own time, in UTC');
        $this->assertSame('2026-01-02T03:04:05+01:00', $driver->findOne('memo', 'kept')['updated_at'], 'as the site wrote it');
        $this->assertNull($driver->findOne('memo', 'empty')['updated_at'], 'written as a save writes it (an empty date field is null), the value itself untouched');
        $this->assertSame('2023-03-03T03:03:03+00:00', $driver->findOne('memo', 'empty')[ChangeDates::FIELD]);
    }

    public function test_backfill_pages_mixed_ids_once(): void
    {
        $driver = new JsonFileDriver($this->dir . '/data');
        $dm = new DataManager(['default' => $driver], 'default');
        $def = new TypeDefinition('page', 'page', 'default', ['title' => new TypeFieldDefinition('title')]);
        $ids = ['1a', '11', '3', '100', '5', 'b', '2', 'a'];
        foreach ($ids as $id) {
            $driver->save('page', $id, ['uuid' => $id, 'title' => $id]);
        }

        $lines = Contexts::run(
            ContextFactory::forTest()->withClock(new FrozenClock(new \DateTimeImmutable('2026-11-22T12:00:00Z'))),
            static fn (): array => (new ChangeDateBackfill($dm, static fn (): array => [$def], chunk: 2))->run(),
        );

        $this->assertSame(['gave 8 records of page a change date (8 from the file time)'], $lines);
        foreach ($ids as $id) {
            $this->assertArrayHasKey(ChangeDates::FIELD, $driver->findOne('page', $id), "{$id} has its date");
        }
    }

    public function test_backfill_pages_ids_of_equal_value_once(): void
    {
        $driver = new JsonFileDriver($this->dir . '/data');
        $dm = new DataManager(['default' => $driver], 'default');
        $def = new TypeDefinition('page', 'page', 'default', ['title' => new TypeFieldDefinition('title')]);
        $ids = ['1', '1.0', '7', '007', '8'];
        foreach ($ids as $id) {
            $driver->save('page', $id, ['uuid' => $id, 'title' => $id]);
        }

        Contexts::run(
            ContextFactory::forTest()->withClock(new FrozenClock(new \DateTimeImmutable('2026-11-22T12:00:00Z'))),
            static fn (): array => (new ChangeDateBackfill($dm, static fn (): array => [$def], chunk: 1))->run(),
        );

        foreach ($ids as $id) {
            $this->assertArrayHasKey(ChangeDates::FIELD, $driver->findOne('page', $id), "{$id} has its date");
        }
    }

    public function test_a_type_without_change_dates_is_left_out(): void
    {
        $driver = new JsonFileDriver($this->dir . '/data');
        $dm = new DataManager(['default' => $driver], 'default');
        $driver->save('log', 'a', ['uuid' => 'a', 'line' => 'x']);

        $this->assertSame([], self::backfill($dm, [new TypeDefinition('log', 'log', 'default', ['line' => new TypeFieldDefinition('line')], changeDates: false)]));
        $this->assertArrayNotHasKey(ChangeDates::FIELD, $driver->findOne('log', 'a'));
    }

    public function test_a_sql_table_without_the_column_is_left_out(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE page (uuid TEXT PRIMARY KEY, title TEXT)");
        $pdo->exec("INSERT INTO page (uuid, title) VALUES ('a', 'A')");
        $dm = new DataManager(['default' => new SqliteDriver($pdo)], 'default');
        $missing = new TypeDefinition('gone', 'gone', 'default', ['title' => new TypeFieldDefinition('title')]);

        $this->assertSame(
            ['warning: page keeps no change dates: its table has no _updated_at column (a table no package syncs, such as one a migration made: add the column there)'],
            self::backfill($dm, [new TypeDefinition('page', 'page', 'default', ['title' => new TypeFieldDefinition('title')]), $missing]),
            'a missing table has nothing to date',
        );
        $this->assertSame([['uuid' => 'a', 'title' => 'A']], $pdo->query('SELECT * FROM page')->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function test_records_whose_write_never_takes_do_not_stop_the_rest_of_the_table(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE page (uuid TEXT PRIMARY KEY, title TEXT, _updated_at TEXT)');
        // A row no write reaches (an empty id): it keeps matching "no date" on every chunk.
        $pdo->exec("INSERT INTO page (uuid, title) VALUES ('', 'stuck'), ('b', 'B'), ('c', 'C')");
        $dm = new DataManager(['default' => new SqliteDriver($pdo)], 'default');
        $def = new TypeDefinition('page', 'page', 'default', ['title' => new TypeFieldDefinition('title')]);

        $lines = Contexts::run(
            ContextFactory::forTest()->withClock(new FrozenClock(new \DateTimeImmutable('2026-11-22T12:00:00Z'))),
            static fn (): array => (new ChangeDateBackfill($dm, static fn (): array => [$def], chunk: 1))->run(),
        );

        $this->assertSame(['gave 2 records of page a change date (2 from now)'], $lines);
        $this->assertSame(['' => null, 'b' => '2026-11-22T12:00:00+00:00', 'c' => '2026-11-22T12:00:00+00:00'], $pdo->query('SELECT uuid, _updated_at FROM page ORDER BY uuid')->fetchAll(\PDO::FETCH_KEY_PAIR));
    }
}
