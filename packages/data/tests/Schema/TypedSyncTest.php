<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Migration\Grammar\MysqlGrammar;
use Preflow\Data\Migration\Grammar\SqliteGrammar;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Query;
use Preflow\Data\Schema\RecordTables;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;

/** Record tables on SQLite: typed when created, never altered after, drift reported. */
final class TypedSyncTest extends TestCase
{
    private string $dir;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/typedsync_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $this->pdo = new \PDO('sqlite:' . $this->dir . '/test.sqlite');
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function testNewTableIsTyped(): void
    {
        $lines = $this->sync()->sync(self::def());

        $this->assertSame(['created table event', 'added unique index uniq_event_slug', 'added index idx_event_city'], $lines);
        $this->assertSame(
            ['uuid' => 'text', 'seats' => 'numeric', 'open' => 'integer', 'starts' => 'text', 'slug' => 'text', 'city' => 'text', 'name' => 'text'],
            (new Schema($this->pdo))->columnTypes('event'),
        );
        $this->assertContains('uniq_event_slug', (new Schema($this->pdo))->indexes('event'));
        $this->assertContains('idx_event_city', (new Schema($this->pdo))->indexes('event'));

        $this->pdo->exec("INSERT INTO event (uuid, slug) VALUES ('a', 'x'), ('b', NULL), ('c', NULL)");
        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO event (uuid, slug) VALUES ('d', 'x')");
    }

    public function testScopedUniqueIndexCoversItsScope(): void
    {
        $def = new TypeDefinition('code', 'code', 'sqlite', [
            'code' => new TypeFieldDefinition('code', unique: ['purpose']),
            'purpose' => new TypeFieldDefinition('purpose'),
        ]);
        $this->sync()->sync($def);

        $this->pdo->exec("INSERT INTO code (uuid, code, purpose) VALUES ('a', 'X1', 'invite'), ('b', 'X1', 'reset')");
        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO code (uuid, code, purpose) VALUES ('c', 'X1', 'invite')");
    }

    public function testNumberKeepsIntegerText(): void
    {
        $this->sync()->sync(self::def());
        $dm = $this->dm();
        $driver = $dm->driver('sqlite');
        foreach (['a' => '12', 'b' => '12.5', 'c' => '9', 'd' => '10'] as $id => $seats) {
            $driver->save('event', $id, ['uuid' => $id, 'seats' => $seats]);
        }

        $this->assertContains($driver->findOne('event', 'a')['seats'], ['12', 12]);
        $this->assertEqualsWithDelta(12.5, (float) $driver->findOne('event', 'b')['seats'], 0.0);
        $sorted = array_column($driver->findMany('event', (new Query())->orderBy('seats'))->items(), 'uuid');
        $this->assertSame(['c', 'd', 'a', 'b'], $sorted);
    }

    public function testExistingColumnIsNeverAltered(): void
    {
        $sync = $this->sync();
        $sync->sync(self::def(), typed: false);
        $before = (new Schema($this->pdo))->columnTypes('event');

        $lines = $sync->sync(self::def());

        $this->assertSame([
            'warning: event.seats is text, expected number; convert the table (see the data README)',
            'warning: event.open is text, expected boolean; convert the table (see the data README)',
            'warning: event.slug has no unique index; convert the table (see the data README)',
        ], $lines);
        $this->assertSame($before, (new Schema($this->pdo))->columnTypes('event'));
        $this->assertSame([], array_values(array_filter((new Schema($this->pdo))->indexes('event'), static fn (string $i): bool => !str_starts_with($i, 'sqlite_'))));
        $this->assertSame($lines, $sync->sync(self::def()), 'nothing changed, so the same warnings again');
    }

    public function testNewColumnOnExistingTableIsTyped(): void
    {
        $sync = $this->sync();
        $sync->sync(new TypeDefinition('event', 'event', 'sqlite', ['name' => new TypeFieldDefinition('name')]));

        $lines = $sync->sync(self::def());

        $this->assertSame([
            'added column event.seats',
            'added column event.open',
            'added column event.starts',
            'added column event.slug',
            'added column event.city',
            'added unique index uniq_event_slug',
            'added index idx_event_city',
        ], $lines);
        $types = (new Schema($this->pdo))->columnTypes('event');
        $this->assertSame('numeric', $types['seats']);
        $this->assertSame('integer', $types['open']);
        $this->assertContains('uniq_event_slug', (new Schema($this->pdo))->indexes('event'));
        $this->assertSame([], $sync->sync(self::def()));
    }

    public function testUniqueOffLeavesOutUniqueIndexesOnly(): void
    {
        $lines = $this->sync()->sync(self::def(), unique: false);

        $this->assertSame(['created table event', 'added index idx_event_city'], $lines);
        $this->assertNotContains('uniq_event_slug', (new Schema($this->pdo))->indexes('event'));
        $this->assertSame('numeric', (new Schema($this->pdo))->columnTypes('event')['seats']);
    }

    public function testUntypedTableIsTextWithoutIndexes(): void
    {
        $this->assertSame(['created table event'], $this->sync()->sync(self::def(), typed: false));
        $this->assertSame(['text'], array_values(array_unique((new Schema($this->pdo))->columnTypes('event'))));
    }

    /** A translatable unique field is a JSON map: checked on save, not indexed. */
    public function testTranslatableUniqueFieldGetsNoIndex(): void
    {
        $def = new TypeDefinition('page', 'page', 'sqlite', ['slug' => new TypeFieldDefinition('slug', translatable: true, unique: true)]);

        $this->assertSame(['created table page'], $this->sync()->sync($def));
        $this->assertSame([], $this->sync()->sync($def));
    }

    public function testIndexNameIsShortenedPastSixtyFourCharacters(): void
    {
        foreach ([new SqliteGrammar(), new MysqlGrammar()] as $g) {
            $this->assertSame('uniq_event_slug', $g->indexName('uniq', 'event', 'slug'));
            $this->assertSame('idx_event_city', $g->indexName('idx', 'event', 'city'));
            $table = str_repeat('t', 50);
            $field = str_repeat('f', 20);
            $full = "uniq_{$table}_{$field}";
            $name = $g->indexName('uniq', $table, $field);
            $this->assertSame('uniq_' . mb_substr("{$table}_{$field}", 0, 50) . '_' . sprintf('%08x', crc32($full)), $name);
            $this->assertSame(64, strlen($name));
        }
    }

    public function testRegistryCollectsEveryTable(): void
    {
        $tables = new RecordTables();
        $tables->add('one', static fn (): array => [RecordTables::entry(self::def())]);
        $tables->add('two', static fn (): array => [RecordTables::entry(self::def(), ['_order'], typed: false, unique: false)]);

        $all = $tables->all();
        $this->assertCount(2, $all);
        $this->assertSame(['_order'], $all[1]['extra']);
        $this->assertFalse($all[1]['typed']);
        $this->assertTrue($all[0]['unique']);
        $this->expectException(\RuntimeException::class);
        $tables->add('one', static fn (): array => []);
    }

    private static function def(): TypeDefinition
    {
        return new TypeDefinition('event', 'event', 'sqlite', [
            'seats' => new TypeFieldDefinition('seats', 'number'),
            'open' => new TypeFieldDefinition('open', 'boolean'),
            'starts' => new TypeFieldDefinition('starts', 'date'),
            'slug' => new TypeFieldDefinition('slug', unique: true),
            'city' => new TypeFieldDefinition('city', index: true),
            'name' => new TypeFieldDefinition('name'),
        ]);
    }

    private function dm(): DataManager
    {
        return new DataManager(
            drivers: ['sqlite' => new SqliteDriver($this->pdo)],
            defaultDriver: 'sqlite',
            typeRegistry: new TypeRegistry($this->dir . '/models'),
        );
    }

    private function sync(): RecordTableSync
    {
        return new RecordTableSync($this->dm());
    }
}
