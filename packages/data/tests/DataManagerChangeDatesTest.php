<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\FrozenClock;
use Preflow\Data\ChangeDates;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Data\VersionFields;
use Preflow\Data\Tests\Fixtures\ModelBridge\DatedNote;
use Preflow\Data\Tests\Fixtures\ModelBridge\PlainNote;
use Preflow\Testing\ContextFactory;
use Preflow\Testing\PlainDriver;

require_once __DIR__ . '/Fixtures/ModelBridge/DatedNote.php';
require_once __DIR__ . '/Fixtures/ModelBridge/PlainNote.php';

/**
 * Change dates (ChangeDates, 0.84.0) beyond what the storage contract pins on every driver: a save
 * that changes nothing keeps the date, versioned and class-backed writes, the writes that name
 * their fields, and storage that cannot hold the key yet.
 */
final class DataManagerChangeDatesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_change_dates_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0755, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    private static function at(string $at, \Closure $write): mixed
    {
        return Contexts::run(ContextFactory::forTest()->withClock(new FrozenClock(new \DateTimeImmutable($at))), $write);
    }

    private static function note(string $storage = 'default', string $table = 'notes'): TypeDefinition
    {
        return new TypeDefinition('note', $table, $storage, ['title' => new TypeFieldDefinition('title'), 'n' => new TypeFieldDefinition('n', 'number')]);
    }

    private function json(?VersionFields $versions = null): DataManager
    {
        $dm = new DataManager(['default' => new JsonFileDriver($this->dir . '/data')], 'default', new TypeRegistry($this->dir . '/models', new Locales(['en'], 'en'), [
            'dated_note' => DatedNote::class,
            'plain_note' => PlainNote::class,
        ]));
        $dm->useVersionFields($versions);

        return $dm;
    }

    private static function versioned(): VersionFields
    {
        return new class implements VersionFields {
            public function of(TypeDefinition $def): ?string
            {
                return '_version';
            }

            public function nonContent(TypeDefinition $def): array
            {
                return [];
            }
        };
    }

    private function stored(DataManager $dm, string $id, string $table = 'notes'): array
    {
        return $dm->driver('default')->findOne($table, $id) ?? [];
    }

    public function test_a_save_that_changes_nothing_keeps_the_date(): void
    {
        $dm = $this->json();
        $def = self::note();
        self::at('2026-02-01T10:00:00Z', static fn () => $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'a', 'title' => 'One', 'n' => 1]), false));
        $file = $this->dir . '/data/notes/a.json';
        $bytes = file_get_contents($file);

        self::at('2026-02-03T10:00:00Z', static fn () => $dm->saveType($dm->findIn($def, 'a'), false));
        $this->assertSame($bytes, file_get_contents($file), 'the same bytes: an unchanged record keeps its date');

        // A value given as text that is stored as a number is no change either.
        self::at('2026-02-04T10:00:00Z', static fn () => $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'a', 'title' => 'One', 'n' => '1']), false));
        $this->assertSame('2026-02-01T10:00:00+00:00', $this->stored($dm, 'a')[ChangeDates::FIELD]);

        self::at('2026-02-05T10:00:00Z', static fn () => $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'a', 'title' => 'Two']), false));
        $this->assertSame('2026-02-05T10:00:00+00:00', $this->stored($dm, 'a')[ChangeDates::FIELD], 'a change moves it');
    }

    public function test_an_unchanged_save_of_a_record_without_a_date_gives_it_one(): void
    {
        $dm = $this->json();
        $def = self::note();
        $dm->driver('default')->save('notes', 'a', ['uuid' => 'a', 'title' => 'Before 0.84']);

        self::at('2026-02-01T10:00:00Z', static fn () => $dm->saveType($dm->findIn($def, 'a'), false));

        $this->assertSame('2026-02-01T10:00:00+00:00', $this->stored($dm, 'a')[ChangeDates::FIELD]);
    }

    public function test_a_versioned_save_moves_the_date_with_the_version(): void
    {
        $dm = $this->json(self::versioned());
        $def = self::note();
        $record = DynamicRecord::fromArray($def, ['uuid' => 'a', 'title' => 'One']);
        self::at('2026-02-01T10:00:00Z', static fn () => $dm->saveType($record, false));
        $this->assertSame([1, '2026-02-01T10:00:00+00:00'], [$record->get('_version'), $record->get(ChangeDates::FIELD)]);

        $same = $dm->findIn($def, 'a');
        self::at('2026-02-02T10:00:00Z', static fn () => $dm->saveType($same, false));
        $this->assertSame([1, '2026-02-01T10:00:00+00:00'], [$this->stored($dm, 'a')['_version'], $this->stored($dm, 'a')[ChangeDates::FIELD]], 'the version kept, the date too');

        $same->set('title', 'Two');
        self::at('2026-02-03T10:00:00Z', static fn () => $dm->saveType($same, false));
        $this->assertSame([2, '2026-02-03T10:00:00+00:00'], [$this->stored($dm, 'a')['_version'], $this->stored($dm, 'a')[ChangeDates::FIELD]]);
    }

    public function test_a_checked_save_moves_the_date_only_with_a_new_version(): void
    {
        $dm = $this->json();
        $def = self::note();
        $record = DynamicRecord::fromArray($def, ['uuid' => 'a', 'title' => 'One']);
        $this->assertTrue(self::at('2026-02-01T10:00:00Z', static fn () => $dm->saveTypeIfVersion($record, 0, validate: false)));
        $this->assertSame('2026-02-01T10:00:00+00:00', $this->stored($dm, 'a')[ChangeDates::FIELD]);

        $loaded = $dm->findIn($def, 'a');
        $this->assertTrue(self::at('2026-02-02T10:00:00Z', static fn () => $dm->saveTypeIfVersion($loaded, 1, 1, validate: false)));
        $this->assertSame('2026-02-01T10:00:00+00:00', $this->stored($dm, 'a')[ChangeDates::FIELD], 'the same version: the date it was loaded with');

        $this->assertTrue(self::at('2026-02-03T10:00:00Z', static fn () => $dm->saveTypeIfVersion($loaded, 1, validate: false)));
        $this->assertSame('2026-02-03T10:00:00+00:00', $this->stored($dm, 'a')[ChangeDates::FIELD]);

        $stale = $dm->findIn($def, 'a');
        $stale->set(ChangeDates::FIELD, 'as loaded');
        $this->assertFalse(self::at('2026-02-04T10:00:00Z', static fn () => $dm->saveTypeIfVersion($stale, 1, validate: false)));
        $this->assertSame('as loaded', $stale->get(ChangeDates::FIELD), 'a refused write leaves the record as it was');
        $this->assertSame('2026-02-03T10:00:00+00:00', $this->stored($dm, 'a')[ChangeDates::FIELD]);
    }

    public function test_writes_that_name_their_fields_leave_the_date(): void
    {
        $dm = $this->json(self::versioned());
        $def = self::note();
        self::at('2026-02-01T10:00:00Z', static fn () => $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'a', 'title' => 'One', 'n' => 1]), false));

        self::at('2026-02-02T10:00:00Z', function () use ($dm, $def): void {
            $dm->incrementIn($def, 'a', 'n', 1, []);
            $dm->updateIf($def, 'a', static fn (): array => ['title' => 'Rotated']);
            $dm->updateFieldsIfVersion($def, 'a', ['title' => 'Kept version'], '_version', 1);
        });

        $stored = $this->stored($dm, 'a');
        $this->assertSame('Kept version', $stored['title']);
        $this->assertSame('2026-02-01T10:00:00+00:00', $stored[ChangeDates::FIELD], 'counters and named-field writes leave it');
    }

    public function test_a_class_backed_type_without_an_updated_field_gets_the_system_key(): void
    {
        $dm = $this->json();
        $note = new PlainNote();
        $note->title = 'One';
        self::at('2026-02-01T10:00:00Z', static fn () => $dm->save($note, false));

        $this->assertSame('2026-02-01T10:00:00+00:00', $this->stored($dm, (string) $note->uuid, 'plain_note')[ChangeDates::FIELD]);

        self::at('2026-02-02T10:00:00Z', static fn () => $dm->save($note, false));
        $this->assertSame('2026-02-01T10:00:00+00:00', $this->stored($dm, (string) $note->uuid, 'plain_note')[ChangeDates::FIELD], 'unchanged: kept');

        $note->title = 'Two';
        self::at('2026-02-03T10:00:00Z', static fn () => $dm->update($note, false));
        $this->assertSame('2026-02-03T10:00:00+00:00', $this->stored($dm, (string) $note->uuid, 'plain_note')[ChangeDates::FIELD]);
    }

    public function test_a_class_backed_type_with_its_own_updated_field_gets_it_on_the_property(): void
    {
        $dm = $this->json();
        $note = new DatedNote();
        $note->title = 'One';
        $note->updatedAt = '2001-01-01 00:00:00';
        self::at('2026-02-01T10:00:00+01:00', static fn () => $dm->insert($note, false));

        $this->assertSame('2026-02-01T09:00:00+00:00', $note->updatedAt, 'the property holds what was written');
        $stored = $this->stored($dm, (string) $note->uuid, 'dated_note');
        $this->assertSame('2026-02-01T09:00:00+00:00', $stored['updatedAt']);
        $this->assertArrayNotHasKey(ChangeDates::FIELD, $stored, 'no second key');

        $imported = new DatedNote();
        $imported->title = 'Old';
        $imported->updatedAt = '2001-01-01T00:00:00+00:00';
        self::at('2026-02-01T10:00:00Z', static fn () => $dm->keepingChangeDates(static fn () => $dm->insert($imported, false)));
        $this->assertSame('2001-01-01T00:00:00+00:00', $this->stored($dm, (string) $imported->uuid, 'dated_note')['updatedAt'], 'kept on import');
    }

    public function test_a_sql_table_without_the_column_saves_as_before_until_migrate_adds_it(): void
    {
        $pdo = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE notes (uuid TEXT PRIMARY KEY, title TEXT, n REAL)');
        $dm = new DataManager(['sql' => new SqliteDriver($pdo)], 'sql');
        $def = self::note('sql');

        self::at('2026-02-01T10:00:00Z', static fn () => $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'a', 'title' => 'One']), false));
        $this->assertNull($dm->changeDateOf($def), 'no column, no date');
        $this->assertSame([['uuid' => 'a', 'title' => 'One', 'n' => null]], $pdo->query('SELECT * FROM notes')->fetchAll(\PDO::FETCH_ASSOC));

        $this->assertContains('added column notes._updated_at', (new RecordTableSync($dm))->sync($def));
        self::at('2026-02-02T10:00:00Z', static fn () => $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'a', 'title' => 'Two']), false));
        $this->assertSame('2026-02-02T10:00:00+00:00', $pdo->query("SELECT _updated_at FROM notes WHERE uuid = 'a'")->fetchColumn());
    }

    public function test_a_storage_that_does_not_describe_its_columns_gets_no_date(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE notes (uuid TEXT PRIMARY KEY, title TEXT, n REAL)');
        $dm = new DataManager(['plain' => new PlainDriver(new SqliteDriver($pdo))], 'plain');
        $def = self::note('plain');

        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'a', 'title' => 'One']), false);

        $this->assertNull($dm->changeDateOf($def));
        $this->assertSame('One', $pdo->query("SELECT title FROM notes WHERE uuid = 'a'")->fetchColumn());
    }
}
