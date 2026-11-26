<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeRegistry;
use Preflow\Fields\FieldsServiceProvider;
use Preflow\Fields\Values\DateRange;
use Preflow\Fields\Values\DateTimeValue;
use Preflow\Fields\Values\TimeOfDay;
use Preflow\Testing\MysqlTestDatabase;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/**
 * The datetime, time and daterange fields on every storage (0.91): the stored shape read back as
 * saved, the checks on save without Folio, the columns, and the sort by a datetime column.
 */
final class DateTimeStoredTest extends TestCase
{
    private string $dir;

    private bool $mysql = false;

    private string $previousZone;

    protected function setUp(): void
    {
        $this->previousZone = date_default_timezone_get();
        date_default_timezone_set('Europe/Zurich');
        $this->dir = sys_get_temp_dir() . '/fields_dates_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousZone);
        if ($this->mysql) {
            MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'json' => ['json'];
        yield 'sqlite' => ['sqlite'];
    }

    #[DataProvider('storages')]
    public function test_every_type_round_trips(string $storage): void
    {
        $this->roundTrip($this->dm($storage));
    }

    #[Group('mysql')]
    public function test_every_type_round_trips_on_mysql(): void
    {
        $this->roundTrip($this->dm('mysql'));
    }

    private function roundTrip(DataManager $dm): void
    {
        $def = $dm->getTypeRegistry()->get('event');
        $dm->saveType(DynamicRecord::fromArray($def, [
            'uuid' => 'e1', 'name' => 'Full', 'starts_at' => '2026-07-15T10:30:00Z', 'opens' => '09:05',
            'stay' => ['start' => '2026-03-01', 'end' => '2026-03-07'],
            'slot' => ['start' => '2026-07-15T07:00:00Z', 'end' => '2026-07-15T15:30:00Z'],
        ]));
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'e2', 'name' => 'Empty', 'starts_at' => null, 'opens' => null, 'stay' => null, 'slot' => null]));

        $full = $dm->findType('event', 'e1');
        $this->assertSame('2026-07-15T10:30:00Z', DateTimeValue::fromStored($full?->get('starts_at'))?->toStored());
        $this->assertSame('09:05', TimeOfDay::fromStored($full?->get('opens'))?->toStored());
        $this->assertSame(['start' => '2026-03-01', 'end' => '2026-03-07'], DateRange::fromStored($full?->get('stay'))?->toStored());
        $this->assertSame(['start' => '2026-07-15T07:00:00Z', 'end' => '2026-07-15T15:30:00Z'], DateRange::fromStored($full?->get('slot'))?->toStored());

        $empty = $dm->findType('event', 'e2');
        $this->assertNull(DateTimeValue::fromStored($empty?->get('starts_at')));
        $this->assertNull(TimeOfDay::fromStored($empty?->get('opens')));
        $this->assertNull(DateRange::fromStored($empty?->get('stay')));
    }

    #[DataProvider('storages')]
    public function test_a_save_refuses_each_type_on_its_field_without_folio(string $storage): void
    {
        $dm = $this->dm($storage);
        $def = $dm->getTypeRegistry()->get('event');
        $save = static fn (array $values) => $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'bad', 'name' => 'x'] + $values));

        foreach ([
            ['starts_at', '2026-07-15 12:30', 'Must be a date and time in UTC, such as 2026-03-29T12:30:00Z.'],
            ['starts_at', '2026-03-29T02:30', '2026-03-29 02:30 does not exist in Europe/Zurich (clocks go forward). Choose another time.'],
            ['opens', '25:00', 'Must be a time of day as hours and minutes, such as 14:30.'],
            ['stay', ['start' => '2026-03-07', 'end' => '2026-03-01'], 'The end (2026-03-01) is before the start (2026-03-07). Choose an end on or after 2026-03-07.'],
            ['stay', ['start' => '2026-03-01', 'end' => null], 'Must be a date range with a start and an end, such as 2026-03-01 – 2026-03-07.'],
            ['slot', ['start' => '2026-07-15T15:00:00Z', 'end' => '2026-07-15T07:00:00Z'], 'The end (2026-07-15 09:00) is before the start (2026-07-15 17:00). Choose an end on or after 2026-07-15 17:00.'],
            ['tokyo', '2026-03-29T02:30:00', 'Must be a date and time in UTC, such as 2026-03-29T12:30:00Z.'],
        ] as [$field, $value, $message]) {
            try {
                $save([$field => $value]);
                $this->fail("saved {$field}");
            } catch (ValidationException $e) {
                $this->assertSame([$field => [$message]], $e->errors(), $field);
            }
        }
        $this->assertNull($dm->findType('event', 'bad'));
    }

    public function test_columns(): void
    {
        $dm = $this->dm('json');
        $fields = $dm->getTypeRegistry()->get('event')->fields;
        $kind = static fn (string $name): ColumnKind => $dm->fieldKinds()->columnKindOf($fields[$name]);

        $this->assertSame(ColumnKind::Date, $kind('starts_at'), 'a datetime sorts and indexes as a date column');
        $this->assertSame(ColumnKind::Text, $kind('opens'), 'a short column only when unique or indexed');
        $this->assertSame(ColumnKind::Short, $dm->fieldKinds()->columnKindOf($fields['opens']->withIndex()));
        $this->assertSame(ColumnKind::Text, $kind('stay'));
        $this->assertSame(ColumnKind::Text, $kind('slot'));
    }

    #[DataProvider('storages')]
    public function test_a_datetime_column_sorts_by_the_instant(string $storage): void
    {
        $dm = $this->dm($storage);
        $def = $dm->getTypeRegistry()->get('event');
        // Written out of order; the site's zone (+01:00 / +02:00) does not decide the order: the instant does.
        foreach (['c' => '2026-07-15T10:30:00Z', 'a' => '2025-12-31T23:30:00Z', 'b' => '2026-03-29T01:00:00Z', 'd' => null] as $id => $when) {
            $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => $id, 'name' => $id, 'starts_at' => $when]));
        }
        $ids = static fn (SortDirection $dir): array => array_map(
            static fn (DynamicRecord $r): string => (string) $r->getId(),
            $dm->queryType('event')->where('starts_at', '!=', null)->orderBy('starts_at', $dir)->get()->items(),
        );

        $this->assertSame(['a', 'b', 'c'], $ids(SortDirection::Asc));
        $this->assertSame(['c', 'b', 'a'], $ids(SortDirection::Desc));
    }

    private function dm(string $storage): DataManager
    {
        file_put_contents($this->dir . '/models/event.json', (string) json_encode([
            'key' => 'event', 'table' => 'event', 'storage' => $storage, 'id_field' => 'uuid',
            'fields' => [
                'name' => ['type' => 'string'],
                'starts_at' => ['type' => 'datetime'],
                'tokyo' => ['type' => 'datetime', 'zone' => 'Asia/Tokyo'],
                'opens' => ['type' => 'time'],
                'stay' => ['type' => 'daterange'],
                'slot' => ['type' => 'daterange', 'with_time' => true],
            ],
        ]));
        $driver = match ($storage) {
            'json' => new JsonFileDriver($this->dir . '/data'),
            'sqlite' => new SqliteDriver(new \PDO('sqlite:' . $this->dir . '/app.sqlite')),
            'mysql' => new MysqlDriver(MysqlTestDatabase::pdo()),
        };
        $this->mysql = $storage === 'mysql';
        $dm = new DataManager([$storage => $driver], $storage, new TypeRegistry($this->dir . '/models'), validatorFactory: new ValidatorFactory(new RuleFactory()));
        FieldsServiceProvider::registerKinds($dm->fieldKinds());
        if ($storage !== 'json') {
            (new RecordTableSync($dm))->sync($dm->getTypeRegistry()->get('event'));
        }

        return $dm;
    }
}
