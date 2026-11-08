<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Observe;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Data\Observe\DataObserver;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;

#[Entity(table: 'gadgets', storage: 'default')]
final class ObservedGadget extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    public string $name = '';
}

final class RecordingObserver implements DataObserver
{
    /** @var list<string> */
    public array $events = [];

    public function read(string $storage, string $type, ?string $id): void
    {
        $this->events[] = "read {$storage}:{$type}" . ($id === null ? '' : "#{$id}");
    }

    public function wrote(string $storage, string $type, ?string $id): void
    {
        $this->events[] = "wrote {$storage}:{$type}" . ($id === null ? '' : "#{$id}");
    }

    public function rawRead(string $storage, string $sql): void
    {
        $this->events[] = "rawRead {$storage}: {$sql}";
    }

    public function rawWrote(string $storage, string $sql): void
    {
        $this->events[] = "rawWrote {$storage}: {$sql}";
    }
}

final class DataManagerObserverTest extends TestCase
{
    private string $dir;
    private \PDO $pdo;
    private DataManager $dm;
    private TypeRegistry $registry;
    private RecordingObserver $observer;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();
        $this->dir = sys_get_temp_dir() . '/dmobs_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE gadgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        foreach (['object' => 'default', 'note' => 'json'] as $key => $storage) {
            file_put_contents($this->dir . "/models/{$key}.json", json_encode([
                'key' => $key, 'table' => $key, 'storage' => $storage, 'id_field' => 'uuid',
                'fields' => ['title' => ['type' => 'string'], '_version' => ['type' => 'number']],
            ]));
        }
        $this->pdo->exec('CREATE TABLE object (uuid TEXT PRIMARY KEY, title TEXT, _version INTEGER)');
        $this->pdo->exec('CREATE TABLE object__published (uuid TEXT PRIMARY KEY, title TEXT, _version INTEGER)');
        $this->registry = new TypeRegistry($this->dir . '/models', new Locales(['en'], 'en'));
        $this->dm = new DataManager(
            drivers: ['default' => new SqliteDriver($this->pdo), 'json' => new JsonFileDriver($this->dir . '/store')],
            typeRegistry: $this->registry,
        );
        $this->observer = new RecordingObserver();
        $this->dm->observe($this->observer);
    }

    protected function tearDown(): void
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** @return list<string> the events since the last call */
    private function events(): array
    {
        $events = $this->observer->events;
        $this->observer->events = [];

        return $events;
    }

    private function object(string $id, string $title = 'T'): DynamicRecord
    {
        return DynamicRecord::fromArray($this->registry->get('object'), ['uuid' => $id, 'title' => $title]);
    }

    public function test_model_writes_and_reads(): void
    {
        $gadget = new ObservedGadget();
        $gadget->name = 'A';
        $this->dm->save($gadget);
        $this->assertSame(['wrote default:gadgets#1'], $this->events(), 'save reports the id the storage gave');

        $other = new ObservedGadget();
        $other->name = 'B';
        $this->dm->insert($other);
        $other->name = 'C';
        $this->dm->update($other);
        $this->assertSame(['wrote default:gadgets#2', 'wrote default:gadgets#2'], $this->events());

        $this->dm->find(ObservedGadget::class, 1);
        $this->dm->find(ObservedGadget::class, 99);
        $this->assertSame(['read default:gadgets#1', 'read default:gadgets#99'], $this->events(), 'a missing record is a read too');

        $this->dm->query(ObservedGadget::class)->get();
        $this->dm->query(ObservedGadget::class)->first();
        $this->dm->query(ObservedGadget::class)->paginate(10);
        $this->assertSame(['read default:gadgets', 'read default:gadgets', 'read default:gadgets'], $this->events());

        $this->dm->delete($gadget);
        $this->dm->delete(ObservedGadget::class, 2);
        $this->assertSame(['wrote default:gadgets#1', 'wrote default:gadgets#2'], $this->events());
    }

    public function test_record_writes_and_reads(): void
    {
        $this->dm->saveType($this->object('o1'));
        $this->assertSame(['wrote default:object#o1'], $this->events());

        $this->assertTrue($this->dm->saveTypeIfVersion($this->object('o2'), 0));
        $this->assertFalse($this->dm->saveTypeIfVersion($this->object('o2'), 0), 'lost race');
        $this->assertSame(['wrote default:object#o2'], $this->events(), 'a refused version write wrote nothing');

        $this->dm->findType('object', 'o1');
        $this->dm->queryType('object')->where('title', 'T')->get();
        $this->dm->queryType('object')->aggregate()->count()->get();
        $this->assertSame(['read default:object#o1', 'read default:object', 'read default:object'], $this->events());

        $this->dm->deleteType('object', 'o1');
        $this->assertSame(['wrote default:object#o1'], $this->events());

        $note = DynamicRecord::fromArray($this->registry->get('note'), ['uuid' => 'n1', 'title' => 'N']);
        $this->dm->saveType($note);
        $this->dm->findType('note', 'n1');
        $this->assertSame(['wrote json:note#n1', 'read json:note#n1'], $this->events(), 'storage is the driver name');
    }

    public function test_published_copy_is_reported_under_its_table(): void
    {
        $def = $this->registry->get('object');
        $shadow = new TypeDefinition($def->key, $def->table . '__published', $def->storage, $def->fields, $def->idField);

        $this->dm->saveType(DynamicRecord::fromArray($shadow, ['uuid' => 'o1', 'title' => 'P']));
        $this->dm->findIn($shadow, 'o1');
        $this->dm->queryIn($shadow)->get();
        $this->dm->deleteIn($shadow, 'o1');

        $this->assertSame([
            'wrote default:object__published#o1',
            'read default:object__published#o1',
            'read default:object__published',
            'wrote default:object__published#o1',
        ], $this->events());
    }

    public function test_raw_reads_and_writes(): void
    {
        $this->dm->raw('SELECT * FROM `object__published` o WHERE o.title = ?', ['x']);
        $this->assertSame(['rawRead default: SELECT * FROM `object__published` o WHERE o.title = ?'], $this->events());

        $this->dm->execute('UPDATE object SET title = ? WHERE uuid = ?', ['y', 'o1']);
        $this->assertSame(['rawWrote default: UPDATE object SET title = ? WHERE uuid = ?'], $this->events());

        $this->dm->raw('DELETE FROM object WHERE uuid = ?', ['o1']);
        $this->assertSame(['rawWrote default: DELETE FROM object WHERE uuid = ?'], $this->events(), 'a write sent through raw() is a write');

        try {
            $this->dm->execute('UPDATE missing_table SET a = 1');
            $this->fail('expected a PDOException');
        } catch (\PDOException) {
        }
        $this->assertSame(['rawWrote default: UPDATE missing_table SET a = 1'], $this->events(), 'reported even when it failed');
    }

    public function test_delete_plan_reports_its_writes(): void
    {
        $this->dm->saveType($this->object('o1'));
        $this->dm->saveType($this->object('o2'));
        $this->events();

        $this->dm->executeDeletePlan($this->dm->deletePlan('object', ['o1', 'o2']));

        $writes = array_values(array_filter($this->events(), static fn (string $e): bool => str_starts_with($e, 'wrote')));
        $this->assertSame(['wrote default:object#o1', 'wrote default:object#o2'], $writes);
    }

    public function test_report_write_for_writes_around_the_manager(): void
    {
        $this->dm->driver('default')->save('object', 'o9', ['uuid' => 'o9', 'title' => 'x']);
        $this->dm->reportWrite('default', 'object');
        $this->dm->reportWrite('default', 'object', 'o9');
        $this->assertSame(['wrote default:object', 'wrote default:object#o9'], $this->events());
    }

    public function test_one_driver_under_several_names_is_one_storage(): void
    {
        $driver = new SqliteDriver($this->pdo);
        $dm = new DataManager(['mysql' => $driver, 'default' => $driver, 'legacy' => $driver, 'json' => new JsonFileDriver($this->dir . '/store'), 'files' => new JsonFileDriver($this->dir . '/files')]);
        $dm->observe($this->observer);
        $def = new TypeDefinition('object', 'object', 'mysql', $this->registry->get('object')->fields, 'uuid');

        $dm->findIn($def, 'o1');
        $dm->raw('SELECT * FROM object', storage: 'legacy');
        $dm->reportWrite('files', 'x');

        $this->assertSame(['read default:object#o1', 'rawRead default: SELECT * FROM object', 'wrote files:x'], $this->events());
    }

    public function test_no_observer_reports_nothing_and_works(): void
    {
        $this->dm->observe(null);
        $this->dm->saveType($this->object('o1'));
        $this->assertSame([['uuid' => 'o1', 'title' => 'T', '_version' => null]], $this->dm->raw('SELECT * FROM object'));
        $this->assertSame(1, $this->dm->execute('UPDATE object SET title = ?', ['U']));
        $this->assertSame([], $this->events());
    }
}
