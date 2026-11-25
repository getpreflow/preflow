<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Capabilities;

use PHPUnit\Framework\TestCase;
use Preflow\Data\AtomicCounters;
use Preflow\Data\CounterReset;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Observe\DataObserver;
use Preflow\Data\StorageDriver;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Testing\PlainDriver;

final class AtomicCountersTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/atomic_counters_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    private static function type(string $storage): TypeDefinition
    {
        return new TypeDefinition(
            key: 'counters',
            table: 'counters',
            storage: $storage,
            fields: [
                'purpose' => new TypeFieldDefinition('purpose', 'string'),
                'hits' => new TypeFieldDefinition('hits', 'number'),
                'window_start' => new TypeFieldDefinition('window_start', 'number'),
            ],
            idField: 'id',
        );
    }

    private function sqlite(): SqliteDriver
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE counters (id TEXT PRIMARY KEY, purpose TEXT, hits INTEGER, window_start INTEGER)');

        return new SqliteDriver($pdo);
    }

    /** @return array<string, StorageDriver> */
    private function drivers(): array
    {
        return [
            'sqlite' => $this->sqlite(),
            'json' => new JsonFileDriver($this->dir),
            'plain' => new PlainDriver($this->sqlite()),
        ];
    }

    public function test_the_built_in_drivers_have_atomic_counters(): void
    {
        $this->assertInstanceOf(AtomicCounters::class, $this->sqlite());
        $this->assertInstanceOf(AtomicCounters::class, new JsonFileDriver($this->dir));
        $this->assertNotInstanceOf(AtomicCounters::class, new PlainDriver($this->sqlite()));
    }

    /** With the capability or without it (read, add and write), the same counts. */
    public function test_increment_in_counts_and_restarts_alike_on_every_driver(): void
    {
        foreach ($this->drivers() as $name => $driver) {
            $dm = new DataManager([$name => $driver], $name);
            $def = self::type($name);
            $window = static fn (int $now): CounterReset => new CounterReset('window_start', $now - 60 + 1, ['window_start' => $now]);

            $this->assertSame(1, $dm->incrementIn($def, 'c1', 'hits', 1, ['purpose' => 'p', 'window_start' => 100], $window(100)), $name);
            $this->assertSame(3, $dm->incrementIn($def, 'c1', 'hits', 2, ['purpose' => 'p', 'window_start' => 159], $window(159)), $name);
            $this->assertSame(1, $dm->incrementIn($def, 'c1', 'hits', 1, ['purpose' => 'p', 'window_start' => 160], $window(160)), "{$name}: restarts at the boundary");
            $this->assertSame(2, $dm->incrementIn($def, 'c1', 'hits', 1, ['purpose' => 'p', 'window_start' => 161]), $name);

            $record = $dm->findIn($def, 'c1');
            $this->assertSame(2, (int) $record?->get('hits'), $name);
            $this->assertSame(160, (int) $record?->get('window_start'), $name);
            $this->assertSame('p', $record?->get('purpose'), $name);
        }
    }

    public function test_increment_in_reports_the_write(): void
    {
        $observer = new class implements DataObserver {
            /** @var list<string> */
            public array $wrote = [];

            public function read(string $storage, string $type, ?string $id): void {}

            public function wrote(string $storage, string $type, ?string $id): void
            {
                $this->wrote[] = "{$storage}:{$type}#{$id}";
            }

            public function rawRead(string $storage, string $sql): void {}

            public function rawWrote(string $storage, string $sql): void {}
        };
        foreach ($this->drivers() as $name => $driver) {
            $dm = new DataManager([$name => $driver], $name);
            $dm->observe($observer);
            $dm->incrementIn(self::type($name), 'c1', 'hits', 1, ['purpose' => 'p', 'window_start' => 100]);
        }

        $this->assertSame(['sqlite:counters#c1', 'json:counters#c1', 'plain:counters#c1'], $observer->wrote);
    }

    public function test_insert_values_must_not_name_the_counter_or_the_id(): void
    {
        $dm = new DataManager(['default' => $this->sqlite()]);
        foreach ([['hits' => 5], ['id' => 'x']] as $insert) {
            try {
                $dm->incrementIn(self::type('default'), 'c1', 'hits', 1, $insert);
                $this->fail('Expected InvalidArgumentException for ' . json_encode($insert));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** Since 0.90: a count past the 64-bit whole numbers is refused alike on every path, writing nothing. */
    public function test_increment_in_refuses_a_count_past_the_largest_whole_number_on_every_driver(): void
    {
        foreach ($this->drivers() as $name => $driver) {
            $dm = new DataManager([$name => $driver], $name);
            $def = self::type($name);
            foreach ([[PHP_INT_MAX - 1, 5, 'largest'], [PHP_INT_MIN + 1, -5, 'smallest']] as [$stored, $by, $word]) {
                $driver->save('counters', 'c1', ['id' => 'c1', 'purpose' => 'p', 'hits' => $stored, 'window_start' => 100], 'id');
                try {
                    $dm->incrementIn($def, 'c1', 'hits', $by, ['purpose' => 'q']);
                    $this->fail("{$name}: adding {$by} to {$stored} was not refused");
                } catch (\OverflowException $e) {
                    $this->assertSame("Adding {$by} to the field [hits] goes past the {$word} whole number the storage keeps ("
                        . ($by > 0 ? PHP_INT_MAX : PHP_INT_MIN) . '); nothing was written. Add a smaller amount or reset the field.', $e->getMessage(), $name);
                }
                $this->assertSame($stored, (int) $driver->findOne('counters', 'c1', 'id')['hits'], "{$name}: nothing written");
                $this->assertSame($stored + ($by > 0 ? 1 : -1), $dm->incrementIn($def, 'c1', 'hits', $by > 0 ? 1 : -1, []), "{$name}: up to the bound it counts");
                $this->assertSame(1, $dm->incrementIn($def, 'c1', 'hits', 1, [], new CounterReset('window_start', 101, ['window_start' => 200])), "{$name}: a restart still counts");
                $driver->delete('counters', 'c1', 'id');
            }
        }
    }
}
